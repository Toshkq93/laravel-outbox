<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Exceptions\TransactionRequired;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\Tests\TestCase;

class StrictModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_recording_outside_a_transaction_throws_despite_the_test_transaction(): void
    {
        // RefreshDatabase keeps a wrapping transaction open; it must not count as the app's own.
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        $this->assertFalse(Outbox::inTransaction());

        $this->expectException(TransactionRequired::class);
        $this->expectExceptionMessage('order.placed');

        Outbox::recordRaw('order.placed', []);
    }

    public function test_recording_inside_a_transaction_works(): void
    {
        DB::transaction(function () {
            $this->assertTrue(Outbox::inTransaction());

            Outbox::recordRaw('order.placed', []);
        });

        $this->assertSame(1, OutboxMessage::query()->count());
    }

    public function test_strict_mode_can_be_disabled(): void
    {
        config(['outbox.outbox.strict' => false]);

        Outbox::recordRaw('order.placed', []);

        $this->assertSame(1, OutboxMessage::query()->count());
    }
}
