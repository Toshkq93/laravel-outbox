<?php

namespace Toshkq93\Outbox\Events;

use Illuminate\Http\Request;

/**
 * A repeated request got the stored response instead of running again.
 */
final class IdempotentRequestReplayed
{
    public function __construct(
        public readonly Request $request,
        public readonly string $key,
        public readonly string $scope,
        public readonly int $status,
    ) {}
}
