<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Toshkq93\Outbox\Events\DuplicateMessageSkipped;
use Toshkq93\Outbox\Events\IdempotencyKeyConflict;
use Toshkq93\Outbox\Events\IdempotentRequestReplayed;
use Toshkq93\Outbox\Events\MessageDead;
use Toshkq93\Outbox\Events\MessageHandled;
use Toshkq93\Outbox\Events\MessagePublished;
use Toshkq93\Outbox\Events\MessagePublishFailed;
use Toshkq93\Outbox\Events\MessageRecorded;
use Toshkq93\Outbox\Events\RelayBatchProcessed;
use Toshkq93\Outbox\Facades\Idempotency;
use Toshkq93\Outbox\Facades\Inbox;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Support\PackageEvents;
use Toshkq93\Outbox\Tests\Fixtures\RecordingPublisher;
use Toshkq93\Outbox\Tests\TestCase;

class PackageEventsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<object> */
    private array $events = [];

    protected function defineRoutes($router): void
    {
        $router->post('/pay', fn () => response(['paid' => true], 201))->middleware('idempotent');
    }

    protected function setUp(): void
    {
        parent::setUp();

        RecordingPublisher::reset();
        Date::setTestNow('2026-11-11 10:00:00');
        config([
            'outbox.outbox.publisher' => 'recording',
            'outbox.outbox.publishers.recording' => ['driver' => RecordingPublisher::class],
            'outbox.outbox.relay.max_attempts' => 2,
            'outbox.outbox.relay.backoff' => [30],
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function listen(string ...$classes): void
    {
        foreach ($classes as $class) {
            Event::listen($class, function (object $event) {
                $this->events[] = $event;
            });
        }
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return list<T>
     */
    private function of(string $class): array
    {
        return array_values(array_filter($this->events, fn ($event) => $event instanceof $class));
    }

    public function test_recording_and_publishing(): void
    {
        $this->listen(MessageRecorded::class, MessagePublished::class, RelayBatchProcessed::class);

        $recorded = DB::transaction(fn () => Outbox::recordRaw('order.placed', ['id' => 1]));
        app(Relay::class)->runOnce(owner: 'relay-1');
        app(Relay::class)->runOnce(owner: 'relay-1');

        $this->assertSame($recorded, $this->of(MessageRecorded::class)[0]->message);

        [$published] = $this->of(MessagePublished::class);
        $this->assertSame($recorded->message_id, $published->message->messageId);
        $this->assertSame('recording', $published->publisher);
        $this->assertSame(1, $published->attempt);

        [$first, $empty] = $this->of(RelayBatchProcessed::class);
        $this->assertSame([1, 1], [$first->result->claimed, $first->result->published]);
        $this->assertSame('relay-1', $first->relay);
        $this->assertGreaterThanOrEqual(0, $first->durationMs);
        $this->assertTrue($empty->result->isEmpty(), 'an empty batch is a heartbeat');
    }

    public function test_failures_and_dead_messages(): void
    {
        $this->listen(MessagePublishFailed::class, MessageDead::class, MessagePublished::class);
        RecordingPublisher::$failing['order.placed'] = true;

        DB::transaction(fn () => Outbox::recordRaw('order.placed', []));
        app(Relay::class)->runOnce();
        $this->travel(31)->seconds();
        app(Relay::class)->runOnce();

        [$retrying, $final] = $this->of(MessagePublishFailed::class);
        $this->assertInstanceOf(RuntimeException::class, $retrying->exception);
        $this->assertSame('Broker rejected order.placed', $retrying->exception->getMessage());
        $this->assertSame([1, true], [$retrying->attempt, $retrying->willRetry]);
        $this->assertSame('2026-11-11 10:00:30', $retrying->retryAt->format('Y-m-d H:i:s'));
        $this->assertSame([2, false, null], [$final->attempt, $final->willRetry, $final->retryAt]);

        [$dead] = $this->of(MessageDead::class);
        $this->assertSame(2, $dead->attempts);
        $this->assertSame('order.placed', $dead->message->type);
        $this->assertSame($final->exception, $dead->exception);
        $this->assertSame([], $this->of(MessagePublished::class));
    }

    public function test_inbox_events(): void
    {
        $this->listen(MessageHandled::class, DuplicateMessageSkipped::class);

        Inbox::once(new Message('m-1', 'invoice.issued', []), 'billing', fn () => null);
        Inbox::once('m-1', 'billing', fn () => null);

        [$handled] = $this->of(MessageHandled::class);
        $this->assertSame(['m-1', 'billing', 'invoice.issued'], [$handled->messageId, $handled->consumer, $handled->type]);

        [$skipped] = $this->of(DuplicateMessageSkipped::class);
        $this->assertSame(['m-1', 'billing', null], [$skipped->messageId, $skipped->consumer, $skipped->type]);
    }

    public function test_a_failing_handler_reports_nothing(): void
    {
        $this->listen(MessageHandled::class, DuplicateMessageSkipped::class);

        try {
            Inbox::once('m-1', 'billing', fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->events);
    }

    public function test_idempotency_events(): void
    {
        $this->listen(IdempotentRequestReplayed::class, IdempotencyKeyConflict::class);

        $this->postJson('/pay', ['amount' => 5], ['Idempotency-Key' => 'k-1'])->assertCreated();
        $this->postJson('/pay', ['amount' => 5], ['Idempotency-Key' => 'k-1'])->assertCreated();
        $this->postJson('/pay', ['amount' => 6], ['Idempotency-Key' => 'k-1'])->assertStatus(422);

        $request = Request::create('/pay', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/json'], content: '{"amount":7}');
        Idempotency::store()->acquire(Idempotency::scope($request), 'k-2', Idempotency::fingerprint($request), 60, 60);
        $this->postJson('/pay', ['amount' => 7], ['Idempotency-Key' => 'k-2'])->assertStatus(409);

        [$replayed] = $this->of(IdempotentRequestReplayed::class);
        $this->assertSame(['k-1', 'ip:127.0.0.1', 201, '/pay'], [$replayed->key, $replayed->scope, $replayed->status, $replayed->request->getPathInfo()]);

        [$mismatch, $busy] = $this->of(IdempotencyKeyConflict::class);
        $this->assertSame(['k-1', IdempotencyKeyConflict::REASON_MISMATCH, 422], [$mismatch->key, $mismatch->reason, $mismatch->status]);
        $this->assertSame(['k-2', IdempotencyKeyConflict::REASON_IN_PROGRESS, 409], [$busy->key, $busy->reason, $busy->status]);
    }

    public function test_events_are_not_built_without_listeners(): void
    {
        $built = false;

        PackageEvents::dispatch(MessageHandled::class, function () use (&$built) {
            $built = true;

            return new MessageHandled('m', 'c', null);
        });
        $this->assertFalse($built);

        Event::listen('Toshkq93\Outbox\Events\*', fn () => null);
        PackageEvents::dispatch(MessageHandled::class, function () use (&$built) {
            $built = true;

            return new MessageHandled('m', 'c', null);
        });
        $this->assertTrue($built, 'wildcard listeners count');
    }

    public function test_events_reach_event_fake_without_listeners(): void
    {
        Event::fake([MessageRecorded::class, MessageHandled::class]);

        DB::transaction(fn () => Outbox::recordRaw('order.placed', []));
        Inbox::once('m-1', 'billing', fn () => null);

        Event::assertDispatched(MessageRecorded::class, fn (MessageRecorded $event) => $event->message->type === 'order.placed');
        Event::assertDispatched(MessageHandled::class);
    }

    public function test_package_events_are_never_outboxed(): void
    {
        $this->listen(MessageRecorded::class);

        DB::transaction(fn () => Outbox::recordRaw('order.placed', []));

        $this->assertCount(1, $this->of(MessageRecorded::class));
        $this->assertSame(1, OutboxMessage::query()->count());
    }
}
