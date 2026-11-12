<?php

namespace Toshkq93\Outbox\Idempotency;

/**
 * The outcome of IdempotencyStore::acquire().
 */
final class Attempt
{
    /**
     * @param  string|null  $fingerprint  fingerprint stored with the key (null if unknown, e.g. a
     *                                    cache lock taken a moment before its record was written)
     * @param  string|null  $owner  token of the request holding the key (Acquired only)
     */
    public function __construct(
        public readonly AttemptStatus $status,
        public readonly string $scope,
        public readonly string $key,
        public readonly ?string $fingerprint,
        public readonly ?string $owner = null,
        public readonly ?StoredResponse $response = null,
    ) {}

    public static function acquired(string $scope, string $key, string $fingerprint, string $owner): self
    {
        return new self(AttemptStatus::Acquired, $scope, $key, $fingerprint, $owner);
    }

    public static function inProgress(string $scope, string $key, ?string $fingerprint): self
    {
        return new self(AttemptStatus::InProgress, $scope, $key, $fingerprint);
    }

    public static function completed(string $scope, string $key, string $fingerprint, StoredResponse $response): self
    {
        return new self(AttemptStatus::Completed, $scope, $key, $fingerprint, null, $response);
    }

    public function matches(string $fingerprint): bool
    {
        return $this->fingerprint === null || hash_equals($this->fingerprint, $fingerprint);
    }
}
