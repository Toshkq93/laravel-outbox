<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Toshkq93\Outbox\Exceptions\TransactionRequired;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxDispatcher;
use Toshkq93\Outbox\Tests\Fixtures\Order;
use Toshkq93\Outbox\Tests\Fixtures\OrderPlaced;
use Toshkq93\Outbox\Tests\Fixtures\OrderStatus;
use Toshkq93\Outbox\Tests\Fixtures\OrderViewed;
use Toshkq93\Outbox\Tests\TestCase;

class EventDispatchTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<object> */
    private array $handled = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(OrderPlaced::class, fn (OrderPlaced $event) => $this->handled[] = $event);
        Event::listen(OrderViewed::class, fn (OrderViewed $event) => $this->handled[] = $event);
    }

    public function test_the_dispatcher_is_decorated(): void
    {
        $this->assertInstanceOf(OutboxDispatcher::class, app('events'));
        $this->assertTrue(Event::hasListeners(OrderPlaced::class));
        $this->assertNotEmpty(Event::getListeners(OrderPlaced::class)); // proxied via __call
    }

    public function test_outboxed_events_are_stored_instead_of_handled(): void
    {
        DB::transaction(function () {
            $order = Order::query()->create(['number' => 'A-1']);

            $this->assertSame([], event(new OrderPlaced($order, OrderStatus::New, CarbonImmutable::now())));
            OrderPlaced::dispatch($order, OrderStatus::Paid, CarbonImmutable::now());
            $this->assertNull(Event::until(new OrderPlaced($order, OrderStatus::Paid, CarbonImmutable::now())));
        });

        $this->assertSame([], $this->handled);
        $this->assertSame(3, OutboxMessage::query()->where('type', OrderPlaced::class)->count());
    }

    public function test_regular_events_are_untouched(): void
    {
        event(new OrderViewed(1));

        $this->assertCount(1, $this->handled);
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_bypass_delivers_straight_to_listeners(): void
    {
        $order = Order::query()->create(['number' => 'A-1']);

        Outbox::bypass(fn () => event(new OrderPlaced($order, OrderStatus::New, CarbonImmutable::now())));

        $this->assertCount(1, $this->handled);
        $this->assertSame(0, OutboxMessage::query()->count());
        $this->assertFalse(Outbox::isBypassing());
    }

    public function test_interception_can_be_disabled(): void
    {
        config(['outbox.outbox.intercept_events' => false]);

        event(new OrderPlaced(Order::query()->create(['number' => 'A-1']), OrderStatus::New, CarbonImmutable::now()));

        $this->assertCount(1, $this->handled);
        $this->assertSame(0, OutboxMessage::query()->count());
    }

    public function test_dispatching_outside_a_transaction_throws_in_strict_mode(): void
    {
        $this->expectException(TransactionRequired::class);

        event(new OrderPlaced(Order::query()->create(['number' => 'A-1']), OrderStatus::New, CarbonImmutable::now()));
    }

    public function test_event_fake_still_captures_outboxed_events(): void
    {
        Event::fake([OrderPlaced::class]);

        event(new OrderPlaced(Order::query()->create(['number' => 'A-1']), OrderStatus::New, CarbonImmutable::now()));

        Event::assertDispatched(OrderPlaced::class);
        $this->assertSame(0, OutboxMessage::query()->count());
    }
}
