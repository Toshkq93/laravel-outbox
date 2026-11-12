<?php

namespace Toshkq93\Outbox\Idempotency\Stores;

use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use RuntimeException;
use Toshkq93\Outbox\Contracts\IdempotencyStore;
use Toshkq93\Outbox\Idempotency\Attempt;
use Toshkq93\Outbox\Idempotency\StoredResponse;

/**
 * Keys in a table with a unique (scope, key) index. The key row is inserted and committed
 * before the request runs, so concurrent requests see it at once; do not call acquire() inside
 * a transaction of your own, or other requests cannot see the key until it commits.
 */
class DatabaseStore implements IdempotencyStore
{
    protected const IN_PROGRESS = 'in_progress';

    protected const COMPLETED = 'completed';

    protected const FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        protected Connection $connection,
        protected string $table = 'idempotency_keys',
    ) {}

    public function acquire(string $scope, string $key, string $fingerprint, int $lockSeconds, int $ttl): Attempt
    {
        // A few rounds cover the row vanishing (pruned) or being taken over between our reads.
        for ($round = 0; $round < 5; $round++) {
            $now = Date::now();
            $owner = Str::random(40);
            $claim = [
                'fingerprint' => $fingerprint,
                'status' => self::IN_PROGRESS,
                'owner' => $owner,
                'locked_until' => $this->time($now->copy()->addSeconds($lockSeconds)),
                'expires_at' => $this->time($now->copy()->addSeconds($ttl)),
            ];

            // ON CONFLICT DO NOTHING / INSERT IGNORE: no exception, so no aborted transaction on PostgreSQL.
            $inserted = $this->query()->insertOrIgnore($claim + [
                'scope' => $scope,
                'key' => $key,
                'created_at' => $this->time($now),
            ]);

            if ($inserted === 1) {
                return Attempt::acquired($scope, $key, $fingerprint, $owner);
            }

            $row = $this->find($scope, $key);

            if ($row === null) {
                continue;
            }

            $expired = $row->expires_at < $this->time($now);
            $abandoned = $row->status === self::IN_PROGRESS && $row->locked_until < $this->time($now);

            // An abandoned run of ANOTHER request keeps the key, so the mismatch is reported.
            if ($expired || ($abandoned && hash_equals($row->fingerprint, $fingerprint))) {
                $taken = $this->query()
                    ->where('id', $row->id)
                    ->where(fn (Builder $query) => $query
                        ->where('expires_at', '<', $this->time($now))
                        ->orWhere(fn (Builder $query) => $query
                            ->where('status', self::IN_PROGRESS)
                            ->where('locked_until', '<', $this->time($now))))
                    ->update($claim + [
                        'response_status' => null,
                        'response_headers' => null,
                        'response_body' => null,
                        'completed_at' => null,
                    ]);

                if ($taken === 1) {
                    return Attempt::acquired($scope, $key, $fingerprint, $owner);
                }

                continue;
            }

            if ($row->status === self::COMPLETED) {
                return Attempt::completed($scope, $key, $row->fingerprint, new StoredResponse(
                    (int) $row->response_status,
                    json_decode((string) $row->response_headers, true) ?: [],
                    (string) base64_decode((string) $row->response_body),
                ));
            }

            return Attempt::inProgress($scope, $key, $row->fingerprint);
        }

        throw new RuntimeException("Could not acquire idempotency key [{$key}]: it keeps changing.");
    }

    public function complete(Attempt $attempt, StoredResponse $response, int $ttl): void
    {
        $now = Date::now();

        $this->owned($attempt)->update([
            'status' => self::COMPLETED,
            'owner' => null,
            'locked_until' => null,
            'response_status' => $response->status,
            'response_headers' => json_encode($response->headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'response_body' => base64_encode($response->body),
            'completed_at' => $this->time($now),
            'expires_at' => $this->time($now->copy()->addSeconds($ttl)),
        ]);
    }

    public function release(Attempt $attempt): void
    {
        $this->owned($attempt)->delete();
    }

    /**
     * Delete expired keys; returns the number deleted.
     */
    public function prune(?DateTimeInterface $before = null, int $chunk = 1000): int
    {
        $before = $this->time(Date::instance($before ?? Date::now()));
        $deleted = 0;

        do {
            $ids = $this->query()->where('expires_at', '<', $before)->orderBy('id')->limit($chunk)->pluck('id')->all();

            $deleted += $ids === [] ? 0 : $this->query()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $deleted;
    }

    protected function find(string $scope, string $key): ?object
    {
        $row = $this->query()->where('scope', $scope)->where('key', $key)->first();

        if ($row !== null) {
            // Compare timestamps as strings in one format, whatever the driver returns.
            $row->expires_at = $this->normalize($row->expires_at);
            $row->locked_until = $row->locked_until === null ? null : $this->normalize($row->locked_until);
        }

        return $row;
    }

    protected function owned(Attempt $attempt): Builder
    {
        return $this->query()
            ->where('scope', $attempt->scope)
            ->where('key', $attempt->key)
            ->where('owner', $attempt->owner)
            ->where('status', self::IN_PROGRESS);
    }

    protected function query(): Builder
    {
        return $this->connection->table($this->table);
    }

    protected function time(DateTimeInterface $time): string
    {
        return $time->format(self::FORMAT);
    }

    protected function normalize(string $value): string
    {
        return $this->time(Carbon::parse($value));
    }
}
