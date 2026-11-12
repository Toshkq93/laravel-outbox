<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Toshkq93\Outbox\Http\Middleware\Idempotent;
use Toshkq93\Outbox\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_config_is_merged(): void
    {
        $this->assertSame('outbox_messages', config('outbox.outbox.table'));
        $this->assertSame('database', config('outbox.idempotency.driver'));
    }

    public function test_outbox_table_is_migrated(): void
    {
        $this->assertTrue(Schema::hasTable('outbox_messages'));
        $this->assertTrue(Schema::hasColumns('outbox_messages', [
            'id', 'message_id', 'type', 'payload', 'status', 'attempts', 'available_at', 'locked_until',
        ]));
    }

    public function test_inbox_table_is_migrated(): void
    {
        $this->assertTrue(Schema::hasColumns('inbox_messages', ['id', 'message_id', 'consumer', 'type', 'processed_at']));
        $this->assertNull(config('outbox.inbox.lock_timeout'));
        $this->assertFalse(config('outbox.inbox.deduplicate_deliveries'));
    }

    public function test_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        foreach (['outbox:relay', 'outbox:retry', 'outbox:prune', 'outbox:status', 'inbox:prune', 'idempotency:prune'] as $command) {
            $this->assertContains($command, $commands);
        }
    }

    public function test_the_idempotent_middleware_alias_is_registered(): void
    {
        $this->assertSame(Idempotent::class, app('router')->getMiddleware()['idempotent']);
        $this->assertTrue(Schema::hasColumns('idempotency_keys', ['scope', 'key', 'fingerprint', 'status', 'owner', 'locked_until', 'response_body', 'expires_at']));
    }
}
