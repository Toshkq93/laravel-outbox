<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Exceptions\UnknownMessageType;
use Toshkq93\Outbox\Exceptions\UnserializablePayload;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\Serialization\DefaultPayloadSerializer;
use Toshkq93\Outbox\Tests\Fixtures\Order;
use Toshkq93\Outbox\Tests\Fixtures\OrderPlaced;
use Toshkq93\Outbox\Tests\Fixtures\OrderStatus;
use Toshkq93\Outbox\Tests\Fixtures\PaymentCaptured;
use Toshkq93\Outbox\Tests\Fixtures\UserRegistered;
use Toshkq93\Outbox\Tests\TestCase;

class SerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_round_trip_through_the_database(): void
    {
        config(['outbox.types' => ['order.placed' => OrderPlaced::class]]);

        $order = Order::query()->create(['number' => 'A-1']);
        $placedAt = CarbonImmutable::parse('2026-11-11 10:00:00.123456', 'Europe/Moscow');

        DB::transaction(fn () => Outbox::record(new OrderPlaced(
            $order, OrderStatus::Paid, $placedAt, lines: [['sku' => 'X', 'qty' => 2, 'at' => $placedAt]], note: 'gift',
        )));

        $event = Outbox::toEvent(OutboxMessage::query()->sole());

        $this->assertInstanceOf(OrderPlaced::class, $event);
        $this->assertTrue($event->order->is($order));
        $this->assertSame(OrderStatus::Paid, $event->status);
        $this->assertInstanceOf(CarbonImmutable::class, $event->placedAt);
        $this->assertTrue($event->placedAt->equalTo($placedAt));
        $this->assertEquals([['sku' => 'X', 'qty' => 2, 'at' => '2026-11-11T10:00:00.123456+03:00']], $event->lines);
        $this->assertSame('gift', $event->note);
    }

    public function test_custom_from_outbox_payload(): void
    {
        DB::transaction(fn () => Outbox::record(new PaymentCaptured(42, 1999)));

        $message = OutboxMessage::query()->sole();
        config(['outbox.types' => ['payment.captured' => PaymentCaptured::class]]);

        $event = Outbox::toEvent($message);

        $this->assertSame([42, 1999], [$event->paymentId(), $event->amount()]);
    }

    public function test_class_without_constructor_gets_public_properties(): void
    {
        $event = new UserRegistered;
        $event->email = 'a@example.com';
        $event->status = OrderStatus::New;

        $serializer = new DefaultPayloadSerializer;
        $payload = $serializer->serialize($event);

        $this->assertSame(['email' => 'a@example.com', 'status' => 'new'], $payload);

        $restored = $serializer->deserialize(UserRegistered::class, $payload);
        $this->assertSame('a@example.com', $restored->email);
        $this->assertSame(OrderStatus::New, $restored->status);
    }

    public function test_optional_and_nullable_arguments_may_be_missing(): void
    {
        $order = Order::query()->create(['number' => 'A-1']);

        $event = (new DefaultPayloadSerializer)->deserialize(OrderPlaced::class, [
            'order' => $order->id, 'status' => 'new', 'placedAt' => '2026-11-11T10:00:00+00:00',
        ]);

        $this->assertSame([], $event->lines);
        $this->assertNull($event->note);
    }

    public function test_missing_required_argument_is_reported(): void
    {
        $this->expectException(UnserializablePayload::class);
        $this->expectExceptionMessage('$status');

        $order = Order::query()->create(['number' => 'A-1']);

        (new DefaultPayloadSerializer)->deserialize(OrderPlaced::class, ['order' => $order->id]);
    }

    public function test_missing_model_fails(): void
    {
        $this->expectException(ModelNotFoundException::class);

        (new DefaultPayloadSerializer)->deserialize(OrderPlaced::class, [
            'order' => 999, 'status' => 'new', 'placedAt' => '2026-11-11T10:00:00+00:00',
        ]);
    }

    public function test_arrayable_values_and_model_collections_are_normalized(): void
    {
        $orders = collect([Order::query()->create(['number' => 'A-1']), Order::query()->create(['number' => 'A-2'])]);

        $payload = (new DefaultPayloadSerializer)->serialize(new class(new Collection(['a' => 1]), Order::query()->get())
        {
            public function __construct(public Collection $meta, public $orders) {}
        });

        $this->assertSame(['meta' => ['a' => 1], 'orders' => $orders->pluck('id')->all()], $payload);
    }

    public function test_unserializable_value_reports_its_path(): void
    {
        $this->expectException(UnserializablePayload::class);
        $this->expectExceptionMessage('[payload.lines.0]');

        (new DefaultPayloadSerializer)->serialize(new class
        {
            public array $lines = [];

            public function __construct()
            {
                $this->lines[] = new \stdClass;
            }
        });
    }

    public function test_unknown_type(): void
    {
        $this->expectException(UnknownMessageType::class);

        Outbox::classOf('order.unknown');
    }
}
