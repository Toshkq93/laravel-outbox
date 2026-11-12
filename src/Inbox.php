<?php

namespace Toshkq93\Outbox;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Toshkq93\Outbox\Events\DuplicateMessageSkipped;
use Toshkq93\Outbox\Events\MessageHandled;
use Toshkq93\Outbox\Exceptions\DuplicateMessage;
use Toshkq93\Outbox\Exceptions\MessageInProgress;
use Toshkq93\Outbox\Models\InboxMessage;
use Toshkq93\Outbox\Support\PackageEvents;

/**
 * Consumer-side deduplication: delivery is at-least-once, the inbox makes handling exactly-once
 * for everything the handler writes to the database.
 */
class Inbox
{
    public function __construct(
        protected Config $config,
        protected DatabaseManager $db,
    ) {}

    /**
     * Run the callback unless this consumer has already handled the message.
     *
     * The (message_id, consumer) key is inserted and the callback runs in ONE transaction on the
     * outbox connection: if the callback throws, the key is rolled back with its writes and the
     * message can be handled again. A duplicate returns false without running the callback.
     *
     * Concurrent duplicates: the second insert waits on the unique index until the first
     * transaction ends: committed → the second is a duplicate (false), rolled back → the second
     * handles the message. With outbox.inbox.lock_timeout (PostgreSQL / MySQL / MariaDB) it waits
     * at most that long and throws MessageInProgress instead.
     *
     * @param  callable(): mixed  $callback
     * @return bool true when the callback ran, false for a duplicate
     *
     * @throws MessageInProgress
     */
    public function once(Message|string $message, string $consumer, callable $callback): bool
    {
        [$messageId, $type] = $message instanceof Message ? [$message->messageId, $message->type] : [$message, null];

        if (! $this->config->get('outbox.inbox.enabled', true)) {
            $callback();

            return true;
        }

        $connection = $this->connection();

        try {
            $connection->transaction(function () use ($connection, $messageId, $consumer, $type, $callback) {
                $this->claim($connection, $messageId, $consumer, $type);

                $callback();
            });
        } catch (DuplicateMessage) {
            PackageEvents::dispatch(DuplicateMessageSkipped::class, fn () => new DuplicateMessageSkipped($messageId, $consumer, $type));

            return false;
        }

        PackageEvents::dispatch(MessageHandled::class, fn () => new MessageHandled($messageId, $consumer, $type));

        return true;
    }

    /**
     * Whether the consumer has handled the message.
     */
    public function processed(Message|string $message, string $consumer): bool
    {
        return $this->query($message, $consumer)->exists();
    }

    /**
     * Forget that the consumer handled the message, so it can be handled again.
     */
    public function forget(Message|string $message, string $consumer): bool
    {
        return $this->query($message, $consumer)->delete() > 0;
    }

    /**
     * Delete keys of messages handled before the cutoff; returns the number deleted.
     *
     * Keep keys longer than a message can possibly be redelivered (relay retries, queue retries,
     * replays), otherwise a late duplicate is handled again.
     */
    public function prune(\DateTimeInterface $before, int $chunk = 1000): int
    {
        $before = Date::instance($before)->format((new InboxMessage)->getDateFormat());
        $deleted = 0;

        // Small id-based chunks keep each DELETE short instead of locking a large range at once.
        do {
            $ids = InboxMessage::query()
                ->where('processed_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            $deleted += $ids === [] ? 0 : InboxMessage::query()->whereKey($ids)->delete();
        } while (count($ids) === $chunk);

        return $deleted;
    }

    /**
     * Default consumer name of a listener or job: the class, "Class@method" for a method other
     * than handle / __invoke, or the class's static inboxConsumer().
     */
    public static function consumerFor(string $class, string $method = 'handle'): string
    {
        if (method_exists($class, 'inboxConsumer')) {
            return $class::inboxConsumer();
        }

        return in_array($method, ['handle', '__invoke'], true) ? $class : "{$class}@{$method}";
    }

    public function connection(): Connection
    {
        return $this->db->connection($this->config->get('outbox.connection'));
    }

    protected function claim(Connection $connection, string $messageId, string $consumer, ?string $type): void
    {
        $restore = $this->limitLockWait($connection);

        try {
            $connection->table($this->config->get('outbox.inbox.table', 'inbox_messages'))->insert([
                'message_id' => $messageId,
                'consumer' => $consumer,
                'type' => $type,
                'processed_at' => Date::now()->format((new InboxMessage)->getDateFormat()),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            $restore(false);

            throw new DuplicateMessage($e->getMessage(), 0, $e);
        } catch (QueryException $e) {
            $restore(false);

            throw $this->isLockTimeout($connection, $e) ? MessageInProgress::make($messageId, $consumer, $e) : $e;
        }

        $restore(true);
    }

    /**
     * Apply outbox.inbox.lock_timeout to the key insert only; the returned callback restores
     * the previous setting so the handler's own queries keep the connection's normal timeout.
     *
     * @return Closure(bool $succeeded): void
     */
    protected function limitLockWait(Connection $connection): Closure
    {
        $timeout = $this->config->get('outbox.inbox.lock_timeout');

        if ($timeout === null) {
            return function () {};
        }

        $timeout = max(0, (int) $timeout);

        switch ($connection->getDriverName()) {
            case 'pgsql':
                $previous = $connection->selectOne("select current_setting('lock_timeout') as value")->value;
                // lock_timeout = 0 disables the limit in PostgreSQL, so "do not wait" is 1ms.
                $connection->selectOne("select set_config('lock_timeout', ?, true)", [max(1, $timeout * 1000).'ms']);

                // Transaction-local: a failed insert aborts the transaction and the rollback undoes it.
                return function (bool $succeeded) use ($connection, $previous) {
                    if ($succeeded) {
                        $connection->selectOne("select set_config('lock_timeout', ?, true)", [$previous]);
                    }
                };

            case 'mysql':
            case 'mariadb':
                $previous = (int) $connection->selectOne('select @@session.innodb_lock_wait_timeout as value')->value;
                // InnoDB counts whole seconds, at least one.
                $connection->statement('set session innodb_lock_wait_timeout = '.max(1, $timeout));

                // Session-wide: restore whatever happened.
                return function () use ($connection, $previous) {
                    $connection->statement('set session innodb_lock_wait_timeout = '.$previous);
                };

            default:
                return function () {};
        }
    }

    protected function isLockTimeout(Connection $connection, QueryException $e): bool
    {
        return match ($connection->getDriverName()) {
            'pgsql' => $e->getCode() === '55P03',
            'mysql', 'mariadb' => ($e->errorInfo[1] ?? null) === 1205,
            default => false,
        };
    }

    /**
     * @return Builder<InboxMessage>
     */
    protected function query(Message|string $message, string $consumer): Builder
    {
        return InboxMessage::query()
            ->where('message_id', $message instanceof Message ? $message->messageId : $message)
            ->where('consumer', $consumer);
    }
}
