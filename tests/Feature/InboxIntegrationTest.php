<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Toshkq93\Outbox\Exceptions\MessageInProgress;
use Toshkq93\Outbox\Facades\Inbox;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Jobs\DeliverMessage;
use Toshkq93\Outbox\Jobs\Middleware\DeduplicateMessage;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Models\InboxMessage;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\CountInvoices;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\InvoiceIssued;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\InvoiceSubscriber;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\IssueInvoiceEntry;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\Ledger;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\MailInvoice;
use Toshkq93\Outbox\Tests\Fixtures\Inbox\NotifyAccounting;
use Toshkq93\Outbox\Tests\TestCase;

class InboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Ledger::reset();
    }

    private function message(string $id = 'm-1', int $invoice = 7): Message
    {
        return new Message($id, InvoiceIssued::class, ['invoiceId' => $invoice]);
    }

    private function deliver(Message $message): void
    {
        app(\Toshkq93\Outbox\Outbox::class)->deliver($message);
    }

    private function ledger(): array
    {
        return DB::table('ledger')->orderBy('id')->pluck('entry')->all();
    }

    public function test_a_should_handle_once_listener_skips_redelivered_messages(): void
    {
        Event::listen(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::listen(InvoiceIssued::class, CountInvoices::class);

        $this->deliver($this->message());
        $this->deliver($this->message());
        $this->deliver($this->message('m-2', 8));

        $this->assertSame(['invoice:7', 'invoice:8'], $this->ledger());
        $this->assertSame(3, count(array_keys(Ledger::$calls, CountInvoices::class)), 'other listeners still run');
        $this->assertSame(['m-1', 'm-2'], Ledger::$current);
        $this->assertSame(
            [['m-1', IssueInvoiceEntry::class, InvoiceIssued::class], ['m-2', IssueInvoiceEntry::class, InvoiceIssued::class]],
            InboxMessage::query()->orderBy('id')->get()->map(fn ($key) => [$key->message_id, $key->consumer, $key->type])->all(),
        );
    }

    public function test_a_failed_listener_is_retried_on_redelivery_while_the_others_are_not(): void
    {
        // The second idempotent listener fails: the first one's work and key are kept,
        // the second one's are rolled back, so a redelivery runs only the second one.
        Event::listen(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::listen(InvoiceIssued::class, function () {
            Ledger::$fail = true;
        });
        Event::listen(InvoiceIssued::class, [NotifyAccounting::class, 'onIssued']);

        try {
            $this->deliver($this->message());
            $this->fail('the listener exception must fail the delivery');
        } catch (RuntimeException $e) {
            $this->assertSame(NotifyAccounting::class.' failed', $e->getMessage());
        }

        $this->assertSame(['invoice:7'], $this->ledger());
        $this->assertTrue(Inbox::processed('m-1', IssueInvoiceEntry::class));
        $this->assertFalse(Inbox::processed('m-1', 'accounting.notify'));

        Ledger::$fail = false;
        Event::forget(InvoiceIssued::class);
        Event::listen(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::listen(InvoiceIssued::class, [NotifyAccounting::class, 'onIssued']);

        $this->deliver($this->message());

        $this->assertSame(['invoice:7', 'accounting:7'], $this->ledger());
        $this->assertTrue(Inbox::processed('m-1', 'accounting.notify'));
    }

    public function test_events_not_delivered_from_the_outbox_reach_the_listener_every_time(): void
    {
        Event::listen(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::listen('outbox:invoice.voided', function (Message $message) {
            event(new InvoiceIssued(100 + $message->payload['n']));
        });

        event(new InvoiceIssued(1));
        event(new InvoiceIssued(2));
        // Dispatched by a listener while another message is being delivered: not that message.
        $this->deliver(new Message('v-1', 'invoice.voided', ['n' => 1]));
        $this->deliver(new Message('v-1', 'invoice.voided', ['n' => 2]));

        $this->assertSame(['invoice:1', 'invoice:2', 'invoice:101', 'invoice:102'], $this->ledger());
        $this->assertSame(0, InboxMessage::query()->count());
    }

    public function test_raw_messages_and_wildcard_listeners_are_deduplicated(): void
    {
        Event::listen('outbox:invoice.*', NotifyAccounting::class.'@onRaw');
        Event::listen('outbox:invoice.paid', IssueInvoiceEntry::class.'@onRaw');

        $this->deliver(new Message('p-1', 'invoice.paid', ['invoice' => 3]));
        $this->deliver(new Message('p-1', 'invoice.paid', ['invoice' => 3]));

        $this->assertSame(['raw:3', 'raw:wildcard:outbox:invoice.paid'], $this->ledger());
        $this->assertEqualsCanonicalizing(
            ['accounting.notify', IssueInvoiceEntry::class.'@onRaw'],
            InboxMessage::query()->pluck('consumer')->all(),
        );
    }

    public function test_subscriber_listeners_are_deduplicated(): void
    {
        Event::subscribe(InvoiceSubscriber::class);

        $this->deliver($this->message());
        $this->deliver($this->message());

        $this->assertSame(['archive:7'], $this->ledger());
        $this->assertTrue(Inbox::processed('m-1', InvoiceSubscriber::class.'@archive'));
    }

    public function test_wrapped_listeners_stay_visible_to_introspection_and_fakes(): void
    {
        Event::listen(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::listen('outbox:invoice.*', NotifyAccounting::class.'@onRaw');

        $this->assertContains(IssueInvoiceEntry::class, app('events')->getRawListeners()[InvoiceIssued::class]);

        Event::fake();

        Event::assertListening(InvoiceIssued::class, IssueInvoiceEntry::class);
        Event::assertListening('outbox:invoice.*', NotifyAccounting::class.'@onRaw');
    }

    public function test_queued_listeners_deduplicate_with_the_job_middleware_and_see_the_message(): void
    {
        config(['queue.default' => 'database']);
        Event::listen(InvoiceIssued::class, MailInvoice::class);

        // Two deliveries of one message queue two listener jobs; both carry the message.
        $this->deliver($this->message());
        $this->deliver($this->message());
        $this->assertSame([], $this->ledger(), 'the listener is queued, not run');

        $payload = json_decode(DB::table('jobs')->value('payload'), true);
        $this->assertSame('m-1', $payload['outbox_message']['message_id']);

        $this->artisan('queue:work', ['connection' => 'database', '--stop-when-empty' => true])->assertSuccessful();

        $this->assertSame(['mail:7'], $this->ledger());
        $this->assertSame(['m-1'], Ledger::$current, 'Outbox::current() is restored in the worker');
        $this->assertTrue(Inbox::processed('m-1', MailInvoice::class));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNull(Outbox::current());
    }

    public function test_deliveries_can_be_deduplicated_as_a_whole(): void
    {
        config(['queue.default' => 'sync', 'outbox.inbox.deduplicate_deliveries' => true]);
        Event::listen(InvoiceIssued::class, CountInvoices::class);

        dispatch(new DeliverMessage($this->message()));
        dispatch(new DeliverMessage($this->message()));

        $this->assertSame([CountInvoices::class], Ledger::$calls);
        $this->assertTrue(Inbox::processed('m-1', DeliverMessage::class));
    }

    public function test_deliveries_are_not_deduplicated_as_a_whole_by_default(): void
    {
        config(['queue.default' => 'sync']);
        Event::listen(InvoiceIssued::class, CountInvoices::class);

        dispatch(new DeliverMessage($this->message()));
        dispatch(new DeliverMessage($this->message()));

        $this->assertSame([CountInvoices::class, CountInvoices::class], Ledger::$calls);
        $this->assertSame([], (new DeliverMessage($this->message()))->middleware());
    }

    public function test_the_middleware_uses_the_jobs_own_message_id_and_consumer(): void
    {
        $job = new class
        {
            public int $runs = 0;

            public function inboxMessageId(): string
            {
                return 'external-1';
            }
        };

        $middleware = new DeduplicateMessage('payments');
        $next = function ($job) {
            return ++$job->runs;
        };

        $this->assertSame(1, $middleware->handle($job, $next));
        $this->assertNull($middleware->handle($job, $next));
        $this->assertSame(1, $job->runs);
        $this->assertTrue(Inbox::processed('external-1', 'payments'));
    }

    public function test_jobs_without_a_message_pass_through(): void
    {
        $job = new \stdClass;
        $runs = 0;

        (new DeduplicateMessage)->handle($job, function () use (&$runs) {
            $runs++;
        });
        (new DeduplicateMessage)->handle($job, function () use (&$runs) {
            $runs++;
        });

        $this->assertSame(2, $runs);
        $this->assertSame(0, InboxMessage::query()->count());
    }

    public function test_a_message_in_progress_elsewhere_releases_the_job(): void
    {
        $this->app->instance(\Toshkq93\Outbox\Inbox::class, new class(app('config'), app('db')) extends \Toshkq93\Outbox\Inbox
        {
            public function once(Message|string $message, string $consumer, callable $callback): bool
            {
                throw MessageInProgress::make(is_string($message) ? $message : $message->messageId, $consumer);
            }
        });

        $job = new class
        {
            use InteractsWithQueue;

            public function inboxMessageId(): string
            {
                return 'busy-1';
            }
        };
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(30);
        $job->setJob($queueJob);

        (new DeduplicateMessage(releaseAfter: 30))->handle($job, fn () => $this->fail('must not run'));

        // Without a queue job to release (dispatchSync, tests) the exception is thrown.
        $this->expectException(MessageInProgress::class);
        (new DeduplicateMessage)->handle(new class
        {
            public function inboxMessageId(): string
            {
                return 'busy-1';
            }
        }, fn () => null);
    }
}
