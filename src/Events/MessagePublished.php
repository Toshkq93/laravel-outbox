<?php

namespace Toshkq93\Outbox\Events;

use Toshkq93\Outbox\Message;

/**
 * The relay handed a message to its publisher.
 */
final class MessagePublished
{
    /**
     * @param  int  $attempt  1 for the first try
     */
    public function __construct(
        public readonly Message $message,
        public readonly string $publisher,
        public readonly int $attempt,
    ) {}
}
