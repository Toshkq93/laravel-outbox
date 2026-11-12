<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Facades\Outbox;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Tests\Fixtures\RecordingPublisher;
use Toshkq93\Outbox\Tests\TestCase;

/**
 * Real concurrency against PostgreSQL / MySQL / MariaDB (skipped on sqlite).
 * Rows must be committed for other connections and processes to see them,
 * so this class does not wrap tests in a transaction.
 */
class ConcurrentRelayTest extends TestCase
{
    use RefreshDatabase;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(Relay::class)->supportsSkipLocked()) {
            $this->markTestSkipped('Needs a database with SELECT ... FOR UPDATE SKIP LOCKED.');
        }

        RecordingPublisher::reset();
        OutboxMessage::query()->delete();
        config([
            'outbox.outbox.strict' => false,
            'outbox.outbox.publisher' => 'recording',
            'outbox.outbox.publishers.recording' => ['driver' => RecordingPublisher::class],
            'database.connections.second' => config('database.connections.'.config('database.default')),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && config('database.connections.second')) {
            DB::connection('second')->disconnect();
            OutboxMessage::query()->delete();
        }

        parent::tearDown();
    }

    public function test_rows_locked_by_another_relay_are_skipped_not_waited_for(): void
    {
        foreach (range(1, 4) as $i) {
            Outbox::recordRaw("m{$i}", []);
        }

        $second = DB::connection('second');
        $this->failFastOnLockWaits($second);
        $this->failFastOnLockWaits(DB::connection());

        // Another relay is in the middle of its claim: it holds row locks on the two oldest messages.
        $second->beginTransaction();
        $held = $second->table('outbox_messages')->orderBy('id')->limit(2)->lockForUpdate()->pluck('id')->all();

        $started = microtime(true);
        $claimed = app(Relay::class)->claim(10, 'relay-a');

        $this->assertLessThan(1.5, microtime(true) - $started, 'claim must not wait for locked rows');
        $this->assertCount(2, $claimed);
        $this->assertSame([], array_intersect($held, $claimed->modelKeys()));

        $second->rollBack();
    }

    public function test_parallel_relay_processes_publish_every_message_exactly_once(): void
    {
        $total = 200;
        foreach (range(1, $total) as $i) {
            Outbox::recordRaw('load', ['i' => $i]);
        }

        $output = tempnam(sys_get_temp_dir(), 'outbox-relay-');
        $processes = [];

        foreach (range(1, 4) as $worker) {
            $processes[] = proc_open(
                [PHP_BINARY, __DIR__.'/../Fixtures/relay-worker.php', $output, "worker-{$worker}"],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[$worker],
                null,
                getenv() + ['DB_CONNECTION' => config('database.default')],
            );
        }

        foreach ($processes as $index => $process) {
            $stdout = stream_get_contents($pipes[$index + 1][1]);
            $stderr = stream_get_contents($pipes[$index + 1][2]);
            $this->assertSame(0, proc_close($process), "worker failed: {$stdout}{$stderr}");
        }

        $lines = array_filter(explode(PHP_EOL, (string) file_get_contents($output)));
        unlink($output);

        $ids = array_map(fn ($line) => explode(' ', $line)[0], $lines);
        $workers = array_unique(array_map(fn ($line) => explode(' ', $line)[1], $lines));

        $this->assertCount($total, $ids, 'every message published once');
        $this->assertCount($total, array_unique($ids), 'no message published twice');
        $this->assertSame($total, OutboxMessage::query()->where('status', OutboxStatus::Published->value)->count());
        $this->assertGreaterThan(1, count($workers), 'work was shared between processes');
    }

    private function failFastOnLockWaits(Connection $connection): void
    {
        $connection->getDriverName() === 'pgsql'
            ? $connection->statement("set lock_timeout = '3s'")
            : $connection->statement('set session innodb_lock_wait_timeout = 3');
    }
}
