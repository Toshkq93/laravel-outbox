<?php

namespace Toshkq93\Outbox\Tests\Feature\Idempotency;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use InvalidArgumentException;
use Toshkq93\Outbox\Facades\Idempotency;
use Toshkq93\Outbox\Idempotency\Stores\CacheStore;

class CacheDriverTest extends IdempotencyTestCase
{
    protected function driver(): string
    {
        return 'cache';
    }

    public function test_the_cache_driver_is_used(): void
    {
        $this->assertInstanceOf(CacheStore::class, Idempotency::store());
    }

    public function test_the_prune_command_has_nothing_to_do(): void
    {
        $this->artisan('idempotency:prune')
            ->expectsOutputToContain('Nothing to prune')
            ->assertSuccessful();
    }

    public function test_a_store_without_locks_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CacheStore(new Repository($this->createStub(Store::class)));
    }
}
