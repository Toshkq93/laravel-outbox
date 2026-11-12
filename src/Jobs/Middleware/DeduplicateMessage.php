<?php

namespace Toshkq93\Outbox\Jobs\Middleware;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\Jobs\SyncJob;
use Toshkq93\Outbox\Exceptions\MessageInProgress;
use Toshkq93\Outbox\Inbox;
use Toshkq93\Outbox\Jobs\DeliverMessage;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Outbox;

/**
 * Job middleware: runs the job through Inbox::once(), so a redelivered message is skipped.
 *
 * The message is taken from, in order:
 *  - DeliverMessage: the message it delivers;
 *  - the job's inboxMessageId(): ?string, for messages from other systems;
 *  - the queued payload: jobs and queued listeners pushed while a message was being handled
 *    carry it (Outbox::current() at dispatch time);
 *  - Outbox::current().
 * A job without a message runs as usual. While the job runs Outbox::current() returns the
 * message and events recorded by it are caused by it.
 *
 * The consumer defaults to the listener class for queued listeners and to the job class otherwise.
 * When another worker holds the same key past outbox.inbox.lock_timeout the job is released
 * back onto the queue after $releaseAfter seconds.
 */
class DeduplicateMessage
{
    public function __construct(
        public ?string $consumer = null,
        public int $releaseAfter = 5,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        $message = $this->message($job);

        if ($message === null) {
            return $next($job);
        }

        $container = Container::getInstance();
        $inbox = $container->make(Inbox::class);
        $result = null;

        $run = function () use ($inbox, $message, $job, $next, &$result) {
            $inbox->once($message, $this->consumer ?? $this->consumerOf($job), function () use ($next, $job, &$result) {
                $result = $next($job);
            });
        };

        try {
            $message instanceof Message ? $container->make(Outbox::class)->handling($message, $run) : $run();
        } catch (MessageInProgress $e) {
            $queueJob = $this->queueJob($job);

            if ($queueJob === null || $queueJob instanceof SyncJob) {
                throw $e;
            }

            $queueJob->release($this->releaseAfter);
        }

        return $result;
    }

    protected function message(object $job): Message|string|null
    {
        if ($job instanceof DeliverMessage) {
            return $job->message();
        }

        if (method_exists($job, 'inboxMessageId') && ($id = $job->inboxMessageId()) !== null) {
            return (string) $id;
        }

        $carried = $this->queueJob($job)?->payload()['outbox_message'] ?? null;

        if (is_array($carried)) {
            return Message::fromArray($carried);
        }

        return Container::getInstance()->make(Outbox::class)->current();
    }

    protected function consumerOf(object $job): string
    {
        return $job instanceof CallQueuedListener
            ? Inbox::consumerFor($job->class, $job->method)
            : Inbox::consumerFor($job::class);
    }

    protected function queueJob(object $job): ?QueueJob
    {
        $queueJob = property_exists($job, 'job') ? $job->job : null;

        return $queueJob instanceof QueueJob ? $queueJob : null;
    }
}
