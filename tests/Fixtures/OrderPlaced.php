<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;

class OrderPlaced implements ShouldBeOutboxed
{
    use Dispatchable;

    public function __construct(
        public Order $order,
        public OrderStatus $status,
        public CarbonImmutable $placedAt,
        public array $lines = [],
        public ?string $note = null,
    ) {}

    public function outboxAggregate(): Order
    {
        return $this->order;
    }
}
