<?php

namespace Toshkq93\Outbox\Contracts;

use Toshkq93\Outbox\Message;

interface Publisher
{
    /**
     * Hand the message to the transport. Throwing marks the attempt as failed:
     * the relay retries it with backoff and eventually marks it dead.
     *
     * Delivery is at-least-once: the same message may be published more than once
     * (e.g. the relay crashed after publishing), so consumers must deduplicate on
     * $message->messageId.
     */
    public function publish(Message $message): void;
}
