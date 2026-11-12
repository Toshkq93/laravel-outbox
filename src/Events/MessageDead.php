<?php

namespace Toshkq93\Outbox\Events;

use Throwable;
use Toshkq93\Outbox\Message;

/**
 * A message ran out of attempts and waits for outbox:retry. Alert on this one.
 */
final class MessageDead
{
    public function __construct(
        public readonly Message $message,
        public readonly string $publisher,
        public readonly Throwable $exception,
        public readonly int $attempts,
    ) {}
}
