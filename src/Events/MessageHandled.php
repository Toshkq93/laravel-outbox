<?php

namespace Toshkq93\Outbox\Events;

/**
 * Inbox::once() ran the handler and committed its key. Inside an outer transaction the key
 * commits with that transaction.
 */
final class MessageHandled
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $consumer,
        public readonly ?string $type,
    ) {}
}
