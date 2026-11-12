<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Jobs\DeliverMessage;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Tests\Fixtures\Order;
use Toshkq93\Outbox\Tests\Fixtures\OrderPlaced;
use Toshkq93\Outbox\Tests\Fixtures\OrderStatus;
use Toshkq93\Outbox\Tests\Fixtures\PaymentCaptured;
use Toshkq93\Outbox\Tests\TestCase;

class QueueDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function placeOrder(): OutboxMessage
    {
        return DB::transaction(function () {
            $order = Order::query()->create(['number' => 'A-1']);

            return Outbox::record(new OrderPlaced($order, OrderStatus::New, CarbonImmutable::now()));
        });
    }

    public function test_the_queue_publisher_pushes_a_delivery_job(): void
    {
        Queue::fake();
        config(['outbox.outbox.publishers.queue' => ['driver' => 'queue', 'connection' => 'redis', 'queue' => 'outbox']]);

        $message = $this->placeOrder();
        app(Relay::class)->runOnce();

        Queue::assertPushedOn('outbox', DeliverMessage::class, function (DeliverMessage $job) use ($message) {
            return $job->connection === 'redis'
                && $job->message()->messageId === $message->message_id
                && $job->message()->type === OrderPlaced::class
                && $job->displayName() === 'outbox: '.OrderPlaced::class;
        });
        $this->assertSame(OutboxStatus::Published, $message->refresh()->status);
    }

    public function test_end_to_end_with_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        $handled = [];
        Event::listen(OrderPlaced::class, function (OrderPlaced $event) use (&$handled) {
            $handled[] = [$event->order->number, Outbox::current()?->messageId];
        });

        $message = $this->placeOrder();
        $this->assertSame([], $handled, 'listeners must not run before the relay publishes');

        app(Relay::class)->runOnce();

        $this->assertSame([['A-1', $message->message_id]], $handled);
        $this->assertNull(Outbox::current());
    }

    public function test_events_recorded_by_listeners_go_to_the_outbox_and_are_caused_by_the_message(): void
    {
        config(['queue.default' => 'sync', 'outbox.outbox.publishers.billing' => ['driver' => 'queue']]);
        $paymentsHandled = 0;

        Event::listen(OrderPlaced::class, function () {
            DB::transaction(fn () => event(new PaymentCaptured(7, 500)));
        });
        Event::listen(PaymentCaptured::class, function () use (&$paymentsHandled) {
            $paymentsHandled++;
        });

        $placed = $this->placeOrder();
        app(Relay::class)->runOnce(1);

        $payment = OutboxMessage::query()->where('type', 'payment.captured')->sole();
        $this->assertSame(0, $paymentsHandled, 'nested ShouldBeOutboxed event must be recorded, not handled');
        $this->assertSame($placed->message_id, $payment->causationId());
        $this->assertSame($placed->correlationId(), $payment->correlationId());

        config(['outbox.types' => ['payment.captured' => PaymentCaptured::class]]);
        $result = app(Relay::class)->runOnce();
        $this->assertSame([], $result->errors);
        $this->assertSame(1, $paymentsHandled);
    }

    public function test_raw_messages_are_dispatched_as_string_events(): void
    {
        $received = [];
        Event::listen('outbox:order.shipped', function (Message $message) use (&$received) {
            $received[] = $message;
        });
        Event::listen('outbox:order.*', function (string $name, array $payload) use (&$received) {
            $received[] = $name;
        });

        $message = new Message('01J0000000000000000000000', 'order.shipped', ['order' => 5], ['correlation_id' => 'c-1']);
        (new DeliverMessage($message))->handle(app(\Toshkq93\Outbox\Outbox::class));

        $this->assertEquals($message, $received[0]);
        $this->assertSame('outbox:order.shipped', $received[1]);
    }

    public function test_the_job_payload_survives_serialization(): void
    {
        $message = new Message('id-1', 'order.shipped', ['order' => 5], ['correlation_id' => 'c-1'], 'order', '5', '2026-11-11T10:00:00.000000+00:00');

        $job = unserialize(serialize(new DeliverMessage($message)));

        $this->assertEquals($message, $job->message());
        $this->assertSame(['outbox', 'outbox:order.shipped', 'message:id-1'], $job->tags());
    }
}
