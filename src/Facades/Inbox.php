<?php

namespace Toshkq93\Outbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static bool once(\Toshkq93\Outbox\Message|string $message, string $consumer, callable $callback)
 * @method static bool processed(\Toshkq93\Outbox\Message|string $message, string $consumer)
 * @method static bool forget(\Toshkq93\Outbox\Message|string $message, string $consumer)
 * @method static int prune(\DateTimeInterface $before, int $chunk = 1000)
 * @method static string consumerFor(string $class, string $method = 'handle')
 * @method static \Illuminate\Database\Connection connection()
 *
 * @see \Toshkq93\Outbox\Inbox
 */
class Inbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Toshkq93\Outbox\Inbox::class;
    }
}
