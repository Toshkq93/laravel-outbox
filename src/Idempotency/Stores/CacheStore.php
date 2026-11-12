<?php

namespace Toshkq93\Outbox\Idempotency\Stores;

use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Toshkq93\Outbox\Contracts\IdempotencyStore;
use Toshkq93\Outbox\Idempotency\Attempt;
use Toshkq93\Outbox\Idempotency\StoredResponse;

/**
 * Keys in a cache store: an atomic lock marks a running request, a cache entry holds the
 * fingerprint and, once finished, the response. Expiry is left to the cache.
 */
class CacheStore implements IdempotencyStore
{
    protected const IN_PROGRESS = 'in_progress';

    protected const COMPLETED = 'completed';

    public function __construct(
        protected Repository $cache,
        protected string $prefix = 'idempotency:',
    ) {
        if (! $cache->getStore() instanceof LockProvider) {
            throw new InvalidArgumentException('The idempotency cache store must support atomic locks.');
        }
    }

    public function acquire(string $scope, string $key, string $fingerprint, int $lockSeconds, int $ttl): Attempt
    {
        if ($completed = $this->completed($scope, $key)) {
            return $completed;
        }

        $owner = Str::random(40);
        $lock = $this->lockProvider()->lock($this->lockName($scope, $key), $lockSeconds, $owner);

        if (! $lock->get()) {
            return $this->completed($scope, $key)
                ?? Attempt::inProgress($scope, $key, $this->record($scope, $key)['fingerprint'] ?? null);
        }

        // Finished between our first look and taking the lock.
        if ($completed = $this->completed($scope, $key)) {
            $lock->release();

            return $completed;
        }

        $this->cache->put($this->recordKey($scope, $key), [
            'status' => self::IN_PROGRESS,
            'fingerprint' => $fingerprint,
        ], $lockSeconds);

        return Attempt::acquired($scope, $key, $fingerprint, $owner);
    }

    public function complete(Attempt $attempt, StoredResponse $response, int $ttl): void
    {
        $lock = $this->ownedLock($attempt);

        if ($lock === null) {
            return;
        }

        $this->cache->put($this->recordKey($attempt->scope, $attempt->key), [
            'status' => self::COMPLETED,
            'fingerprint' => $attempt->fingerprint,
            'response' => $response->toArray(),
        ], $ttl);

        $lock->release();
    }

    public function release(Attempt $attempt): void
    {
        $lock = $this->ownedLock($attempt);

        if ($lock === null) {
            return;
        }

        $this->cache->forget($this->recordKey($attempt->scope, $attempt->key));
        $lock->release();
    }

    protected function completed(string $scope, string $key): ?Attempt
    {
        $record = $this->record($scope, $key);

        return ($record['status'] ?? null) === self::COMPLETED
            ? Attempt::completed($scope, $key, $record['fingerprint'], StoredResponse::fromArray($record['response']))
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function record(string $scope, string $key): ?array
    {
        $record = $this->cache->get($this->recordKey($scope, $key));

        return is_array($record) ? $record : null;
    }

    protected function ownedLock(Attempt $attempt): ?\Illuminate\Contracts\Cache\Lock
    {
        $lock = $this->lockProvider()->restoreLock($this->lockName($attempt->scope, $attempt->key), (string) $attempt->owner);

        return $lock instanceof Lock && ! $lock->isOwnedByCurrentProcess() ? null : $lock;
    }

    protected function lockProvider(): LockProvider
    {
        /** @var LockProvider */
        return $this->cache->getStore();
    }

    protected function recordKey(string $scope, string $key): string
    {
        return $this->prefix.hash('sha256', $scope."\0".$key);
    }

    protected function lockName(string $scope, string $key): string
    {
        return $this->recordKey($scope, $key).':lock';
    }
}
