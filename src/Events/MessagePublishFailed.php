<?php

namespace Toshkq93\Outbox\Events;

use DateTimeInterface;
use Throwable;
use Toshkq93\Outbox\Message;

/**
 * Publishing a message failed. With $willRetry it is retried at $retryAt, otherwise it is now
 * dead (MessageDead follows).
 */
final class MessagePublishFailed
{
    public function __construct(
        public readonly Message $message,
        public readonly string $publisher,
        public readonly Throwable $exception,
        public readonly int $attempt,
        public readonly bool $willRetry,
        public readonly ?DateTimeInterface $retryAt,
    ) {}
}
