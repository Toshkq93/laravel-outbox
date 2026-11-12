<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Toshkq93\Outbox\Tests\TestCase;

/**
 * Requests with one Idempotency-Key from separate processes, for both drivers (the cache driver
 * on the database cache store). Needs a database server shared between processes, and committed
 * rows, so this class does not wrap tests in a transaction.
 */
class ConcurrentIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private string $log;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('Needs a database server shared between processes.');
        }

        $this->clean();
        $this->log = tempnam(sys_get_temp_dir(), 'outbox-idempotency-');
    }

    protected function tearDown(): void
    {
        if (isset($this->log)) {
            @unlink($this->log);
            $this->clean();
        }

        parent::tearDown();
    }

    public static function drivers(): array
    {
        return ['database' => ['database'], 'cache' => ['cache']];
    }

    #[DataProvider('drivers')]
    public function test_a_repeat_during_the_first_request_gets_409_and_after_it_the_replay(string $driver): void
    {
        $first = $this->start('first', 1500, $driver);
        $this->waitUntilInside('first');

        $started = microtime(true);
        $during = $this->finish($this->start('second', 0, $driver));
        $this->assertLessThan(1.4, microtime(true) - $started, '409 is immediate, not after waiting');

        $this->assertSame(['201', 'false', 'first'], $this->finish($first));
        $after = $this->finish($this->start('third', 0, $driver));

        $this->assertSame('409', $during[0]);
        $this->assertSame(['201', 'true', 'first'], $after);
        $this->assertSame(['first'], $this->ledger());
    }

    #[DataProvider('drivers')]
    public function test_many_simultaneous_requests_run_the_handler_once(string $driver): void
    {
        $workers = array_map(fn ($i) => $this->start("w{$i}", 500, $driver), range(1, 6));

        $results = array_map(fn ($worker) => $this->finish($worker), $workers);
        $summary = implode(' | ', array_map(fn ($result) => implode(' ', $result), $results));

        $executed = array_filter($results, fn ($result) => $result[0] === '201' && $result[1] === 'false');
        $this->assertCount(1, $executed, $summary);
        foreach ($results as $result) {
            $this->assertTrue($result[0] === '409' || $result[0] === '201', $summary);
        }
        $this->assertCount(1, $this->ledger());
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(string $name, int $holdMs, string $driver): array
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../Fixtures/idempotency-worker.php', $this->log, $name, (string) $holdMs, $driver],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv() + ['DB_CONNECTION' => config('database.default')],
        );

        return [$process, $pipes];
    }

    /**
     * @param  array{0: resource, 1: array<int, resource>}  $worker
     * @return list<string> status, replayed, run-by
     */
    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), "worker failed: {$stdout}{$stderr}");

        $lines = explode(PHP_EOL, $stdout);

        return explode(' ', (string) end($lines));
    }

    private function waitUntilInside(string $name): void
    {
        $deadline = microtime(true) + 15;

        while (! str_contains((string) file_get_contents($this->log), "{$name} inside")) {
            $this->assertLessThan($deadline, microtime(true), "{$name} never entered its handler");
            usleep(20_000);
        }
    }

    /**
     * @return list<string>
     */
    private function ledger(): array
    {
        return DB::table('ledger')->orderBy('id')->pluck('entry')->all();
    }

    private function clean(): void
    {
        foreach (['idempotency_keys', 'cache', 'cache_locks', 'ledger'] as $table) {
            DB::table($table)->delete();
        }
    }
}
