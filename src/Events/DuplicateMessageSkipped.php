<?php

namespace Toshkq93\Outbox\Events;

/**
 * Inbox::once() skipped a message the consumer had already handled.
 */
final class DuplicateMessageSkipped
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $consumer,
        public readonly ?string $type,
    ) {}
}
