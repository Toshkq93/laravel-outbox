<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

enum OrderStatus: string
{
    case New = 'new';
    case Paid = 'paid';
}
