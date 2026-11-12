<?php

namespace Toshkq93\Outbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Types in @method signatures avoid commas (array<string, mixed>, array{0: ..., 1: ...}): PhpStorm splits
 * parameters on them. The precise types are on \Toshkq93\Outbox\Outbox::record() / recordRaw().
 *
 * @method static \Toshkq93\Outbox\Models\OutboxMessage record(object $event, array<mixed> $headers = [], \Illuminate\Database\Eloquent\Model|array<mixed>|null $aggregate = null, ?string $publisher = null)
 * @method static \Toshkq93\Outbox\Models\OutboxMessage recordRaw(string $type, array<mixed> $payload, array<mixed> $headers = [], \Illuminate\Database\Eloquent\Model|array<mixed>|null $aggregate = null, ?string $publisher = null)
 * @method static object toEvent(\Toshkq93\Outbox\Models\OutboxMessage $message)
 * @method static mixed bypass(callable $callback)
 * @method static void deliver(\Toshkq93\Outbox\Message $message)
 * @method static mixed handling(\Toshkq93\Outbox\Message $message, callable $callback, ?object $event = null)
 * @method static \Toshkq93\Outbox\Message|null current()
 * @method static \Toshkq93\Outbox\Message|null messageFor(mixed $event)
 * @method static bool isBypassing()
 * @method static mixed causedBy(\Toshkq93\Outbox\Models\OutboxMessage|string $messageId, ?string $correlationId, callable $callback)
 * @method static void resolveCorrelationIdUsing(?\Closure $resolver)
 * @method static bool inTransaction()
 * @method static \Illuminate\Database\Connection connection()
 * @method static string typeOf(object $event)
 * @method static string classOf(string $type)
 *
 * @see \Toshkq93\Outbox\Outbox
 */
class Outbox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Toshkq93\Outbox\Outbox::class;
    }
}
