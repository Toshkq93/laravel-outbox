<?php

namespace Toshkq93\Outbox\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Another worker is handling the same message for the same consumer right now and did not
 * finish within outbox.inbox.lock_timeout. Retry later: if that worker succeeds the retry is
 * skipped as a duplicate, if it fails the retry handles the message.
 */
class MessageInProgress extends RuntimeException implements OutboxException
{
    public static function make(string $messageId, string $consumer, ?Throwable $previous = null): self
    {
        return new self(
            "Message [{$messageId}] is being handled by consumer [{$consumer}] in another transaction.",
            0,
            $previous,
        );
    }
}
