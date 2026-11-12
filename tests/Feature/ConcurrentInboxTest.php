<?php

namespace Toshkq93\Outbox\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Toshkq93\Outbox\Facades\Inbox;
use Toshkq93\Outbox\Models\InboxMessage;
use Toshkq93\Outbox\Tests\TestCase;

/**
 * Two (or more) consumer processes receive the same message at the same time.
 * Needs a server database (PostgreSQL / MySQL / MariaDB): the in-memory sqlite database is
 * private to this process. Rows must be committed for the workers to see them, so this class
 * does not wrap tests in a transaction.
 */
class ConcurrentInboxTest extends TestCase
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
        $this->log = tempnam(sys_get_temp_dir(), 'outbox-inbox-');
    }

    protected function tearDown(): void
    {
        if (isset($this->log)) {
            @unlink($this->log);
            $this->clean();
        }

        parent::tearDown();
    }

    public function test_the_second_worker_waits_for_the_first_and_skips_the_duplicate(): void
    {
        $first = $this->start('first', 1500);
        $this->waitUntilInside('first');
        $second = $this->start('second', 0);

        [$firstResult] = $this->finish($first);
        [$secondResult, $waited] = $this->finish($second);

        $this->assertSame('handled', $firstResult);
        $this->assertSame('duplicate', $secondResult);
        $this->assertGreaterThan(700, $waited, 'the second insert waits on the unique index');
        $this->assertSame(['first'], $this->ledger());
        $this->assertSame(1, InboxMessage::query()->count());
    }

    public function test_when_the_first_worker_fails_the_waiting_one_handles_the_message(): void
    {
        $first = $this->start('first', 1500, fail: true);
        $this->waitUntilInside('first');
        $second = $this->start('second', 0);

        [$firstResult] = $this->finish($first);
        [$secondResult] = $this->finish($second);

        $this->assertSame('failed', $firstResult);
        $this->assertSame('handled', $secondResult);
        $this->assertSame(['second'], $this->ledger(), 'the failed attempt left nothing behind');
        $this->assertTrue(Inbox::processed('race-1', 'racer'));
    }

    public function test_with_a_lock_timeout_the_second_worker_gives_up_with_message_in_progress(): void
    {
        $first = $this->start('first', 4000);
        $this->waitUntilInside('first');
        $second = $this->start('second', 0, lockTimeout: 1);

        [$secondResult, $waited] = $this->finish($second);
        [$firstResult] = $this->finish($first);

        $this->assertSame('in-progress', $secondResult);
        $this->assertLessThan(3500, $waited, 'gave up before the first worker finished');
        $this->assertSame('handled', $firstResult);
        $this->assertSame(['first'], $this->ledger());
    }

    public function test_many_simultaneous_workers_handle_the_message_exactly_once(): void
    {
        $workers = array_map(fn ($i) => $this->start("w{$i}", 300), range(1, 6));

        $results = array_map(fn ($worker) => $this->finish($worker)[0], $workers);

        $this->assertSame(1, count(array_keys($results, 'handled')), implode(', ', $results));
        $this->assertSame(5, count(array_keys($results, 'duplicate')), implode(', ', $results));
        $this->assertCount(1, $this->ledger());
    }

    /**
     * @return array{0: resource, 1: array<int, resource>}
     */
    private function start(string $name, int $holdMs, bool $fail = false, ?int $lockTimeout = null): array
    {
        $command = [PHP_BINARY, __DIR__.'/../Fixtures/inbox-worker.php', $this->log, $name, (string) $holdMs, $fail ? '1' : '0'];

        if ($lockTimeout !== null) {
            $command[] = (string) $lockTimeout;
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv() + ['DB_CONNECTION' => config('database.default')],
        );

        return [$process, $pipes];
    }

    /**
     * @param  array{0: resource, 1: array<int, resource>}  $worker
     * @return array{0: string, 1: int} result and elapsed milliseconds
     */
    private function finish(array $worker): array
    {
        [$process, $pipes] = $worker;
        $stdout = trim((string) stream_get_contents($pipes[1]));
        $stderr = stream_get_contents($pipes[2]);

        $this->assertSame(0, proc_close($process), "worker failed: {$stdout}{$stderr}");

        $lines = explode(PHP_EOL, $stdout);
        [$result, $elapsed] = explode(' ', (string) end($lines)) + [1 => '0'];

        return [$result, (int) $elapsed];
    }

    /**
     * Block until the worker is inside its handler, i.e. holds the inbox key uncommitted.
     */
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
        InboxMessage::query()->delete();
        DB::table('ledger')->delete();
    }
}
