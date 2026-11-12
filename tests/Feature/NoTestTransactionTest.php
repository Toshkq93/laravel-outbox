<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Exceptions\TransactionRequired;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\Tests\TestCase;

/**
 * Strict mode with the framework's regular transactions manager, as in production.
 * The wrapping test transaction was begun before the swap, so the new manager does not know it.
 */
class NoTestTransactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance('db.transactions', $manager = new DatabaseTransactionsManager);
        DB::connection()->setTransactionManager($manager);
    }

    public function test_strict_mode_with_the_regular_transactions_manager(): void
    {
        $this->assertFalse(Outbox::inTransaction());

        DB::transaction(function () {
            $this->assertTrue(Outbox::inTransaction());

            Outbox::recordRaw('order.placed', []);
        });

        $this->assertSame(1, OutboxMessage::query()->count());

        $this->expectException(TransactionRequired::class);
        Outbox::recordRaw('order.placed', []);
    }
}
