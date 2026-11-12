<?php

namespace Toshkq93\Outbox\Tests\Fixtures\Inbox;

use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Facades\Outbox;

/** Shared side-effect log of the inbox fixtures. */
class Ledger
{
    /** @var list<string> */
    public static array $calls = [];

    /** @var list<?string> message ids seen through Outbox::current() */
    public static array $current = [];

    public static bool $fail = false;

    public static function reset(): void
    {
        self::$calls = [];
        self::$current = [];
        self::$fail = false;
    }

    public static function write(string $who, string $entry): void
    {
        self::$calls[] = $who;
        self::$current[] = Outbox::current()?->messageId;

        DB::table('ledger')->insert(['entry' => $entry]);

        if (self::$fail) {
            throw new \RuntimeException("{$who} failed");
        }
    }
}
