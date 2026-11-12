<?php

namespace Toshkq93\Outbox\Publishers;

use Illuminate\Contracts\Bus\Dispatcher;
use Toshkq93\Outbox\Contracts\Publisher;
use Toshkq93\Outbox\Jobs\DeliverMessage;
use Toshkq93\Outbox\Message;

/**
 * Publishes to a Laravel queue: a DeliverMessage job per message, handled by queue:work,
 * which dispatches the event to this application's listeners (Outbox::deliver()).
 */
class QueuePublisher implements Publisher
{
    public function __construct(
        protected Dispatcher $bus,
        protected ?string $connection = null,
        protected ?string $queue = null,
    ) {}

    public function publish(Message $message): void
    {
        $job = (new DeliverMessage($message))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        $this->bus->dispatch($job);
    }
}
