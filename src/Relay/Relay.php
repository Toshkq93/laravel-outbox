<?php

namespace Toshkq93\Outbox\Relay;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;
use Toshkq93\Outbox\Events\MessageDead;
use Toshkq93\Outbox\Events\MessagePublished;
use Toshkq93\Outbox\Events\MessagePublishFailed;
use Toshkq93\Outbox\Events\RelayBatchProcessed;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Publishers\PublisherManager;
use Toshkq93\Outbox\Support\PackageEvents;

/**
 * Moves messages from the outbox table to their publisher.
 *
 * 1. claim: in a short transaction, select due messages (FOR UPDATE SKIP LOCKED where the
 *    database supports it, so parallel relays never wait for or take each other's rows) and
 *    lease them to this relay with locked_until / locked_by. The lease alone protects
 *    databases without SKIP LOCKED (sqlite), and frees the rows if the relay dies.
 * 2. publish each message outside any transaction.
 * 3. mark it published, or record the failure and schedule the next attempt with backoff;
 *    after max_attempts it is marked dead (see outbox:retry).
 */
class Relay
{
    protected ?string $owner = null;

    public function __construct(
        protected Config $config,
        protected PublisherManager $publishers,
    ) {}

    /**
     * Claim and publish one batch.
     */
    public function runOnce(?int $limit = null, ?string $owner = null): RelayResult
    {
        $owner ??= $this->owner();
        $started = microtime(true);
        $messages = $this->claim($limit ?? (int) $this->config->get('outbox.outbox.relay.batch_size', 100), $owner);

        $published = $failed = $dead = 0;
        $errors = [];

        foreach ($messages as $message) {
            $exception = $this->publish($message, $owner);

            if ($exception === null) {
                $published++;

                continue;
            }

            $errors[$message->message_id] = $exception::class.': '.$exception->getMessage();
            $this->fail($message, $owner, $exception) ? $dead++ : $failed++;
        }

        $result = new RelayResult($messages->count(), $published, $failed, $dead, $errors);

        PackageEvents::dispatch(RelayBatchProcessed::class, fn () => new RelayBatchProcessed(
            $result,
            $owner,
            round((microtime(true) - $started) * 1000, 3),
        ));

        return $result;
    }

    /**
     * Lease up to $limit due messages to $owner, oldest first.
     *
     * @return Collection<int, OutboxMessage>
     */
    public function claim(int $limit, string $owner): Collection
    {
        $now = Date::now();
        $until = $now->copy()->addSeconds((int) $this->config->get('outbox.outbox.relay.lease', 60));

        $ids = $this->connection()->transaction(function () use ($limit, $owner, $now, $until) {
            $query = $this->due($now)->select('id')->orderBy('id')->limit($limit);

            if ($this->config->get('outbox.outbox.relay.ordered', false)) {
                $this->onlyHeadOfAggregate($query);
            }

            if ($this->supportsSkipLocked()) {
                $query->lock('for update skip locked');
            }

            $ids = $query->pluck('id')->all();

            if ($ids === []) {
                return [];
            }

            // Re-checking the lease makes the claim safe without SKIP LOCKED: a row another
            // relay leased in the meantime is simply not updated, so it is not ours.
            $this->unleased(OutboxMessage::query()->whereKey($ids), $now)->update([
                'locked_until' => $this->timestamp($until),
                'locked_by' => $owner,
            ]);

            return $ids;
        });

        if ($ids === []) {
            return new Collection;
        }

        return OutboxMessage::query()->whereKey($ids)->where('locked_by', $owner)->orderBy('id')->get();
    }

    /**
     * A unique name for this relay process.
     */
    public function owner(): string
    {
        return $this->owner ??= sprintf('%s:%d:%s', gethostname() ?: 'relay', getmypid(), Str::random(8));
    }

    public function supportsSkipLocked(): bool
    {
        return in_array($this->connection()->getDriverName(), ['pgsql', 'mysql', 'mariadb'], true);
    }

    /**
     * Publish one message; returns the exception, or null on success.
     */
    protected function publish(OutboxMessage $message, string $owner): ?Throwable
    {
        try {
            $this->publishers->publisher($message->publisher)->publish($message->toMessage());
        } catch (Throwable $e) {
            return $e;
        }

        $this->leasedBy($message, $owner)->update([
            'status' => OutboxStatus::Published->value,
            'published_at' => $this->timestamp(Date::now()),
            'attempts' => $message->attempts + 1,
            'last_error' => null,
            'locked_until' => null,
            'locked_by' => null,
        ]);

        PackageEvents::dispatch(MessagePublished::class, fn () => new MessagePublished(
            $message->toMessage(),
            $this->publisherName($message),
            $message->attempts + 1,
        ));

        return null;
    }

    /**
     * Record a failed attempt; returns true when the message is now dead.
     */
    protected function fail(OutboxMessage $message, string $owner, Throwable $exception): bool
    {
        $attempts = $message->attempts + 1;
        $dead = $attempts >= (int) $this->config->get('outbox.outbox.relay.max_attempts', 10);
        $retryAt = Date::now()->addSeconds($this->backoff($attempts));

        $this->leasedBy($message, $owner)->update([
            'status' => ($dead ? OutboxStatus::Dead : OutboxStatus::Pending)->value,
            'attempts' => $attempts,
            'last_error' => Str::limit($exception::class.': '.$exception->getMessage(), 4000),
            'available_at' => $this->timestamp($retryAt),
            'locked_until' => null,
            'locked_by' => null,
        ]);

        $publisher = $this->publisherName($message);

        PackageEvents::dispatch(MessagePublishFailed::class, fn () => new MessagePublishFailed(
            $message->toMessage(), $publisher, $exception, $attempts, ! $dead, $dead ? null : $retryAt,
        ));

        if ($dead) {
            PackageEvents::dispatch(MessageDead::class, fn () => new MessageDead(
                $message->toMessage(), $publisher, $exception, $attempts,
            ));
        }

        return $dead;
    }

    protected function publisherName(OutboxMessage $message): string
    {
        return $message->publisher ?? $this->publishers->defaultName();
    }

    /**
     * Seconds to wait after the given failed attempt; the last configured value repeats.
     */
    public function backoff(int $attempt): int
    {
        $backoff = array_values((array) $this->config->get('outbox.outbox.relay.backoff', [1, 5, 30, 120, 600]));

        if ($backoff === []) {
            return 0;
        }

        return (int) ($backoff[min($attempt, count($backoff)) - 1] ?? end($backoff));
    }

    /**
     * @return Builder<OutboxMessage>
     */
    protected function due(DateTimeInterface $now): Builder
    {
        return $this->unleased(
            OutboxMessage::query()
                ->where('status', OutboxStatus::Pending->value)
                ->where('available_at', '<=', $this->timestamp($now)),
            $now,
        );
    }

    /**
     * @param  Builder<OutboxMessage>  $query
     * @return Builder<OutboxMessage>
     */
    protected function unleased(Builder $query, DateTimeInterface $now): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('locked_until')
            ->orWhere('locked_until', '<=', $this->timestamp($now)));
    }

    /**
     * Ordered mode: skip a message while an earlier message of the same aggregate is not
     * published yet (pending, leased or dead), so each aggregate is published in order.
     *
     * @param  Builder<OutboxMessage>  $query
     */
    protected function onlyHeadOfAggregate(Builder $query): void
    {
        $table = $query->getModel()->getTable();

        $query->whereNotExists(fn (QueryBuilder $earlier) => $earlier
            ->selectRaw('1')
            ->from($table, 'earlier')
            ->whereColumn('earlier.aggregate_type', $table.'.aggregate_type')
            ->whereColumn('earlier.aggregate_id', $table.'.aggregate_id')
            ->whereColumn('earlier.id', '<', $table.'.id')
            ->where('earlier.status', '!=', OutboxStatus::Published->value));
    }

    /**
     * @return Builder<OutboxMessage>
     */
    protected function leasedBy(OutboxMessage $message, string $owner): Builder
    {
        return OutboxMessage::query()->whereKey($message->getKey())->where('locked_by', $owner);
    }

    /**
     * Timestamps in the model's format (with microseconds), so string comparison on sqlite stays correct.
     */
    protected function timestamp(DateTimeInterface $date): string
    {
        return $date->format((new OutboxMessage)->getDateFormat());
    }

    protected function connection(): Connection
    {
        return (new OutboxMessage)->getConnection();
    }
}
