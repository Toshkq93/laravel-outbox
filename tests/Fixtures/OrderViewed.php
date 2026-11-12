<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

/** A regular event that must never touch the outbox. */
class OrderViewed
{
    public function __construct(public int $orderId) {}
}
