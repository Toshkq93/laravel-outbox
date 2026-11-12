<?php

namespace Toshkq93\Outbox\Events;

use Toshkq93\Outbox\Relay\RelayResult;

/**
 * The relay processed a batch (also an empty one: a heartbeat of a running relay).
 */
final class RelayBatchProcessed
{
    public function __construct(
        public readonly RelayResult $result,
        public readonly string $relay,
        public readonly float $durationMs,
    ) {}
}
