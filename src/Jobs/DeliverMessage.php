<?php

namespace Toshkq93\Outbox\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Toshkq93\Outbox\Jobs\Middleware\DeduplicateMessage;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Outbox;

/**
 * Queued by QueuePublisher; hands the message to the application's listeners.
 */
class DeliverMessage implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /**
     * Stored as an array, so queued jobs survive changes to the Message class.
     *
     * @var array<string, mixed>
     */
    public array $message;

    public function __construct(Message $message)
    {
        $this->message = $message->toArray();
    }

    public function handle(Outbox $outbox): void
    {
        $outbox->deliver($this->message());
    }

    /**
     * With outbox.inbox.deduplicate_deliveries the whole delivery (all synchronous listeners)
     * runs in Inbox::once(), so a redelivered message is skipped.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return config('outbox.inbox.enabled', true) && config('outbox.inbox.deduplicate_deliveries', false)
            ? [new DeduplicateMessage]
            : [];
    }

    public function message(): Message
    {
        return Message::fromArray($this->message);
    }

    public function displayName(): string
    {
        return 'outbox: '.$this->message['type'];
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['outbox', 'outbox:'.$this->message['type'], 'message:'.$this->message['message_id']];
    }
}
