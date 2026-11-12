<?php

namespace Toshkq93\Outbox\Events;

use Toshkq93\Outbox\Models\OutboxMessage;

/**
 * A message was stored in the outbox. Dispatched inside the recording transaction: if it
 * rolls back, the message is gone (use ShouldHandleEventsAfterCommit on listeners that care).
 */
final class MessageRecorded
{
    public function __construct(public readonly OutboxMessage $message) {}
}
