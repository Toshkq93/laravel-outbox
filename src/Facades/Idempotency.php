<?php

namespace Toshkq93\Outbox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Toshkq93\Outbox\Contracts\IdempotencyStore store(?string $driver = null)
 * @method static void resolveScopeUsing(?\Closure $resolver)
 * @method static void fingerprintUsing(?\Closure $resolver)
 * @method static string scope(\Illuminate\Http\Request $request)
 * @method static string fingerprint(\Illuminate\Http\Request $request)
 *
 * @see \Toshkq93\Outbox\Idempotency\Idempotency
 */
class Idempotency extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Toshkq93\Outbox\Idempotency\Idempotency::class;
    }
}
