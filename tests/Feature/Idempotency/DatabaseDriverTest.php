<?php

namespace Toshkq93\Outbox\Tests\Feature\Idempotency;

use Illuminate\Support\Facades\Date;
use Toshkq93\Outbox\Facades\Idempotency;
use Toshkq93\Outbox\Idempotency\Stores\DatabaseStore;
use Toshkq93\Outbox\Models\IdempotencyKey;

class DatabaseDriverTest extends IdempotencyTestCase
{
    protected function driver(): string
    {
        return 'database';
    }

    public function test_the_database_driver_is_used(): void
    {
        $this->assertInstanceOf(DatabaseStore::class, Idempotency::store());
    }

    public function test_the_row_holds_the_response(): void
    {
        $this->postJson('/orders', ['sku' => 'A'], ['Idempotency-Key' => 'key-1'])->assertCreated();

        $row = IdempotencyKey::query()->sole();
        $this->assertSame('ip:127.0.0.1', $row->scope);
        $this->assertSame('key-1', $row->key);
        $this->assertSame('completed', $row->status);
        $this->assertSame(64, strlen($row->fingerprint));
        $this->assertNull($row->owner);
        $this->assertSame(201, $row->response_status);
        $this->assertJson(base64_decode($row->response_body));
        $this->assertSame('2026-11-12 10:00:00', $row->expires_at->format('Y-m-d H:i:s'));
    }

    public function test_the_prune_command_deletes_expired_keys(): void
    {
        $this->postJson('/orders', [], ['Idempotency-Key' => 'old']);
        Date::setTestNow('2026-11-12 09:00:00');
        $this->postJson('/orders', [], ['Idempotency-Key' => 'recent']);
        Date::setTestNow('2026-11-12 11:00:00');

        $this->artisan('idempotency:prune', ['--chunk' => 1])
            ->expectsOutputToContain('1 expired idempotency key(s) pruned.')
            ->assertSuccessful();

        $this->assertSame(['recent'], IdempotencyKey::query()->pluck('key')->all());
    }
}
