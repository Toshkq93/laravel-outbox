<?php

namespace Toshkq93\Outbox\Events;

use Illuminate\Http\Request;

/**
 * A request was rejected because of its key: 409 (REASON_IN_PROGRESS, the first request is still
 * running) or 422 (REASON_MISMATCH, the key was used with a different request).
 */
final class IdempotencyKeyConflict
{
    public const REASON_IN_PROGRESS = 'in_progress';

    public const REASON_MISMATCH = 'mismatch';

    public function __construct(
        public readonly Request $request,
        public readonly string $key,
        public readonly string $scope,
        public readonly string $reason,
        public readonly int $status,
    ) {}
}
