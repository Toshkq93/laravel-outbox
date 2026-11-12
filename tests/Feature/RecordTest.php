<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Tests\Fixtures\Order;
use Toshkq93\Outbox\Tests\Fixtures\OrderPlaced;
use Toshkq93\Outbox\Tests\Fixtures\OrderStatus;
use Toshkq93\Outbox\Tests\Fixtures\PaymentCaptured;
use Toshkq93\Outbox\Tests\TestCase;

class RecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_event_with_defaults(): void
    {
        $message = DB::transaction(function () {
            $order = Order::query()->create(['number' => 'A-1']);

            return Outbox::record(new OrderPlaced($order, OrderStatus::New, CarbonImmutable::parse('2026-11-11 10:00:00.123456', 'UTC')));
        });

        $stored = OutboxMessage::query()->sole();

        $this->assertTrue($stored->is($message));
        $this->assertSame(OrderPlaced::class, $stored->type);
        $this->assertSame(7, Uuid::fromString($stored->message_id)->getFields()->getVersion());
        $this->assertEquals([
            'order' => $stored->aggregate_id,
            'status' => 'new',
            'placedAt' => '2026-11-11T10:00:00.123456+00:00',
            'lines' => [],
            'note' => null,
        ], $stored->payload);
        $this->assertSame(['correlation_id' => $stored->message_id], $stored->headers);
        $this->assertSame(Order::class, $stored->aggregate_type);
        $this->assertNull($stored->publisher);
        $this->assertSame(OutboxStatus::Pending, $stored->status);
        $this->assertSame(0, $stored->attempts);
        $this->assertNotNull($stored->available_at);
        $this->assertNull($stored->published_at);
    }

    public function test_event_methods_customise_the_message(): void
    {
        DB::transaction(fn () => Outbox::record(new PaymentCaptured(42, 1999)));

        $stored = OutboxMessage::query()->sole();

        $this->assertSame('payment.captured', $stored->type);
        $this->assertSame(['payment' => 42, 'amount_cents' => 1999], $stored->payload);
        $this->assertSame(2, $stored->header('schema_version'));
        $this->assertSame(['payment', '42'], [$stored->aggregate_type, $stored->aggregate_id]);
        $this->assertSame('billing', $stored->publisher);
    }

    public function test_explicit_arguments_override_event_methods(): void
    {
        DB::transaction(fn () => Outbox::record(
            new PaymentCaptured(42, 1999),
            headers: ['schema_version' => 3, 'tenant' => 'acme'],
            aggregate: ['invoice', 7],
            publisher: 'audit',
        ));

        $stored = OutboxMessage::query()->sole();

        $this->assertSame(3, $stored->header('schema_version'));
        $this->assertSame('acme', $stored->header('tenant'));
        $this->assertSame(['invoice', '7'], [$stored->aggregate_type, $stored->aggregate_id]);
        $this->assertSame('audit', $stored->publisher);
    }

    public function test_type_alias_from_config(): void
    {
        config(['outbox.types' => ['order.placed' => OrderPlaced::class]]);

        DB::transaction(fn () => Outbox::record(new OrderPlaced(
            Order::query()->create(['number' => 'A-1']), OrderStatus::Paid, CarbonImmutable::now(),
        )));

        $this->assertSame('order.placed', OutboxMessage::query()->sole()->type);
    }

    public function test_it_records_a_raw_message(): void
    {
        DB::transaction(fn () => Outbox::recordRaw('order.shipped', ['order' => 5, 'carrier' => 'dhl'], aggregate: ['order', 5]));

        $stored = OutboxMessage::query()->sole();

        $this->assertSame('order.shipped', $stored->type);
        $this->assertSame(['order' => 5, 'carrier' => 'dhl'], $stored->payload);
        $this->assertSame(['order', '5'], [$stored->aggregate_type, $stored->aggregate_id]);
    }

    public function test_a_rolled_back_transaction_discards_the_message(): void
    {
        try {
            DB::transaction(function () {
                Outbox::recordRaw('order.shipped', ['order' => 5]);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_invalid_aggregate_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DB::transaction(fn () => Outbox::recordRaw('x', [], aggregate: ['type' => 'order']));
    }

    public function test_caused_by_propagates_correlation_and_causation(): void
    {
        $first = DB::transaction(fn () => Outbox::recordRaw('order.placed', []));

        $second = Outbox::causedBy($first, null, fn () => DB::transaction(fn () => Outbox::recordRaw('order.reserved', [])));
        $third = Outbox::causedBy($second, null, fn () => DB::transaction(fn () => Outbox::recordRaw('order.shipped', [])));

        $this->assertSame($first->message_id, $second->correlationId());
        $this->assertSame($first->message_id, $second->causationId());
        $this->assertSame($first->message_id, $third->correlationId());
        $this->assertSame($second->message_id, $third->causationId());

        $after = DB::transaction(fn () => Outbox::recordRaw('order.cancelled', []));
        $this->assertSame($after->message_id, $after->correlationId());
        $this->assertNull($after->causationId());
    }

    public function test_correlation_id_resolver(): void
    {
        Outbox::resolveCorrelationIdUsing(fn () => 'req-123');

        $message = DB::transaction(fn () => Outbox::recordRaw('order.placed', []));

        $this->assertSame('req-123', $message->correlationId());

        Outbox::resolveCorrelationIdUsing(null);
    }
}
