<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Tests\Fixtures\RecordingPublisher;
use Toshkq93\Outbox\Tests\TestCase;

class RelayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RecordingPublisher::reset();
        Date::setTestNow('2026-11-11 10:00:00');

        config([
            'outbox.outbox.publisher' => 'recording',
            'outbox.outbox.publishers.recording' => ['driver' => RecordingPublisher::class],
            'outbox.outbox.relay.backoff' => [10, 60],
            'outbox.outbox.relay.max_attempts' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function record(string $type, ?array $aggregate = null, ?string $publisher = null): OutboxMessage
    {
        return DB::transaction(fn () => Outbox::recordRaw($type, ['type' => $type], aggregate: $aggregate, publisher: $publisher));
    }

    private function relay(): Relay
    {
        return app(Relay::class);
    }

    public function test_it_publishes_due_messages_oldest_first(): void
    {
        $first = $this->record('a');
        $this->record('b');

        $result = $this->relay()->runOnce();

        $this->assertSame([2, 2, 0, 0], [$result->claimed, $result->published, $result->failed, $result->dead]);
        $this->assertSame(['a', 'b'], RecordingPublisher::types());
        $this->assertSame($first->message_id, RecordingPublisher::$published[0]->messageId);
        $this->assertSame($first->correlationId(), RecordingPublisher::$published[0]->correlationId());

        $first->refresh();
        $this->assertSame(OutboxStatus::Published, $first->status);
        $this->assertSame(1, $first->attempts);
        $this->assertTrue($first->published_at->equalTo(Date::now()));
        $this->assertNull($first->locked_by);
        $this->assertNull($first->locked_until);

        $this->assertTrue($this->relay()->runOnce()->isEmpty());
        $this->assertCount(2, RecordingPublisher::$published);
    }

    public function test_batch_size_limits_a_run(): void
    {
        foreach (['a', 'b', 'c'] as $type) {
            $this->record($type);
        }

        $this->assertSame(2, $this->relay()->runOnce(2)->claimed);
        $this->assertSame(['a', 'b'], RecordingPublisher::types());
        $this->assertSame(1, $this->relay()->runOnce(2)->claimed);
    }

    public function test_messages_not_due_or_leased_are_skipped(): void
    {
        $later = $this->record('later');
        $later->forceFill(['available_at' => Date::now()->addMinute()])->save();

        $leased = $this->record('leased');
        $leased->forceFill(['locked_until' => Date::now()->addSeconds(30), 'locked_by' => 'other-relay'])->save();

        $this->assertTrue($this->relay()->runOnce()->isEmpty());

        // The other relay died: its lease expires and the message is taken over.
        Date::setTestNow(Date::now()->addSeconds(31));
        $this->relay()->runOnce();
        $this->assertSame(['leased'], RecordingPublisher::types());

        Date::setTestNow(Date::now()->addMinute());
        $this->relay()->runOnce();
        $this->assertSame(['leased', 'later'], RecordingPublisher::types());
    }

    public function test_claim_leases_messages_to_the_owner(): void
    {
        $message = $this->record('a');
        config(['outbox.outbox.relay.lease' => 90]);

        $claimed = $this->relay()->claim(10, 'relay-1');

        $this->assertSame([$message->id], $claimed->modelKeys());
        $this->assertSame('relay-1', $claimed->first()->locked_by);
        $this->assertTrue($claimed->first()->locked_until->equalTo(Date::now()->addSeconds(90)));

        $this->assertTrue($this->relay()->claim(10, 'relay-2')->isEmpty());
    }

    public function test_failures_back_off_and_end_dead(): void
    {
        RecordingPublisher::$failing['a'] = true;
        $message = $this->record('a');

        $result = $this->relay()->runOnce();
        $this->assertSame([1, 0, 1, 0], [$result->claimed, $result->published, $result->failed, $result->dead]);
        $this->assertStringContainsString('Broker rejected a', $result->errors[$message->message_id]);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('RuntimeException: Broker rejected a', $message->last_error);
        $this->assertTrue($message->available_at->equalTo(Date::now()->addSeconds(10)));
        $this->assertNull($message->locked_by);

        // Not due before the backoff has passed.
        $this->assertTrue($this->relay()->runOnce()->isEmpty());

        Date::setTestNow(Date::now()->addSeconds(10));
        $this->relay()->runOnce();
        $this->assertTrue($message->refresh()->available_at->equalTo(Date::now()->addSeconds(60)));

        Date::setTestNow(Date::now()->addSeconds(60));
        $result = $this->relay()->runOnce();
        $this->assertSame(1, $result->dead);
        $this->assertSame(OutboxStatus::Dead, $message->refresh()->status);
        $this->assertSame(3, $message->attempts);

        Date::setTestNow(Date::now()->addDay());
        $this->assertTrue($this->relay()->runOnce()->isEmpty());
    }

    public function test_a_success_after_failures_clears_the_error(): void
    {
        RecordingPublisher::$failing['a'] = true;
        $message = $this->record('a');
        $this->relay()->runOnce();

        unset(RecordingPublisher::$failing['a']);
        Date::setTestNow(Date::now()->addSeconds(10));
        $this->relay()->runOnce();

        $message->refresh();
        $this->assertSame(OutboxStatus::Published, $message->status);
        $this->assertSame(2, $message->attempts);
        $this->assertNull($message->last_error);
    }

    public function test_backoff_repeats_the_last_value(): void
    {
        $this->assertSame([10, 60, 60, 60], array_map(fn ($attempt) => $this->relay()->backoff($attempt), [1, 2, 3, 10]));

        config(['outbox.outbox.relay.backoff' => []]);
        $this->assertSame(0, $this->relay()->backoff(1));
    }

    public function test_message_publisher_overrides_the_default(): void
    {
        config(['outbox.outbox.publishers.audit' => ['driver' => RecordingPublisher::class]]);
        $this->record('a', publisher: 'audit');
        $missing = $this->record('b', publisher: 'missing');

        $result = $this->relay()->runOnce();

        $this->assertSame(['a'], RecordingPublisher::types());
        $this->assertStringContainsString('Outbox publisher [missing] is not defined', $result->errors[$missing->message_id]);
    }

    public function test_ordered_mode_publishes_one_message_per_aggregate_at_a_time(): void
    {
        config(['outbox.outbox.relay.ordered' => true]);

        $this->record('order-1.placed', ['order', 1]);
        $this->record('order-1.paid', ['order', 1]);
        $this->record('order-2.placed', ['order', 2]);
        $this->record('no-aggregate');

        $this->relay()->runOnce();
        $this->assertSame(['order-1.placed', 'order-2.placed', 'no-aggregate'], RecordingPublisher::types());

        $this->relay()->runOnce();
        $this->assertSame('order-1.paid', RecordingPublisher::types()[3]);
    }

    public function test_ordered_mode_blocks_an_aggregate_behind_a_failed_message(): void
    {
        config(['outbox.outbox.relay.ordered' => true, 'outbox.outbox.relay.max_attempts' => 1]);
        RecordingPublisher::$failing['order-1.placed'] = true;

        $this->record('order-1.placed', ['order', 1]);
        $this->record('order-1.paid', ['order', 1]);

        $this->assertSame(1, $this->relay()->runOnce()->dead);

        Date::setTestNow(Date::now()->addDay());
        $this->assertTrue($this->relay()->runOnce()->isEmpty());
        $this->assertSame([], RecordingPublisher::types());

        // Without ordering the later message goes out regardless.
        config(['outbox.outbox.relay.ordered' => false]);
        $this->relay()->runOnce();
        $this->assertSame(['order-1.paid'], RecordingPublisher::types());
    }
}
