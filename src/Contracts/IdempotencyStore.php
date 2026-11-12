<?php

namespace Toshkq93\Outbox\Contracts;

use Toshkq93\Outbox\Idempotency\Attempt;
use Toshkq93\Outbox\Idempotency\StoredResponse;

/**
 * Storage of idempotency keys. Implementations must make acquire() atomic across processes:
 * of concurrent requests with one key exactly one gets an Acquired attempt.
 */
interface IdempotencyStore
{
    /**
     * Take the key for $lockSeconds, or report the state it is in.
     * An expired key, or one whose lock ran out (its request crashed), can be taken again.
     *
     * @param  int  $ttl  seconds the key lives (replaced on complete())
     */
    public function acquire(string $scope, string $key, string $fingerprint, int $lockSeconds, int $ttl): Attempt;

    /**
     * Store the response of an acquired attempt for $ttl seconds and release the lock.
     * Does nothing if the attempt no longer holds the key.
     */
    public function complete(Attempt $attempt, StoredResponse $response, int $ttl): void;

    /**
     * Forget an acquired attempt, so the key can be used again. Does nothing if the attempt
     * no longer holds the key.
     */
    public function release(Attempt $attempt): void;
}
