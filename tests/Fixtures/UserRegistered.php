<?php

namespace Toshkq93\Outbox\Tests\Fixtures;

use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;

/** No constructor: public properties are serialized and assigned back. */
class UserRegistered implements ShouldBeOutboxed
{
    public string $email = '';

    public ?OrderStatus $status = null;
}
