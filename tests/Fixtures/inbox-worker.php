<?php

/**
 * A separate consumer process for ConcurrentInboxTest:
 *   php inbox-worker.php <log-file> <name> <hold-ms> <fail:0|1> [lock-timeout-seconds]
 * Handles message "race-1" for consumer "racer" through Inbox::once(): inside the handler it
 * writes a ledger row, logs "<name> inside", holds the transaction open for <hold-ms> and then
 * commits or (fail = 1) throws. Prints "<result> <elapsed-ms>" as the last line; result is handled, duplicate,
 * in-progress or failed. DB_* environment variables select the database, as for the suite.
 */

use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Toshkq93\Outbox\Exceptions\MessageInProgress;
use Toshkq93\Outbox\Inbox;
use Toshkq93\Outbox\OutboxServiceProvider;

// Vendor deprecation notices must not pollute the result line.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__.'/../../vendor/autoload.php';

[, $log, $name, $hold, $fail] = $argv;
$lockTimeout = $argv[5] ?? null;

$app = Application::create(options: ['extra' => ['providers' => [OutboxServiceProvider::class]]]);

$app['config']->set('database.default', getenv('DB_CONNECTION'));
$app['config']->set('outbox.inbox.lock_timeout', $lockTimeout === null ? null : (int) $lockTimeout);

$started = microtime(true);

try {
    $handled = $app->make(Inbox::class)->once('race-1', 'racer', function () use ($log, $name, $hold, $fail) {
        DB::table('ledger')->insert(['entry' => $name]);
        file_put_contents($log, "{$name} inside".PHP_EOL, FILE_APPEND | LOCK_EX);
        usleep((int) $hold * 1000);

        if ($fail === '1') {
            throw new RuntimeException("{$name} failed");
        }
    });
    $result = $handled ? 'handled' : 'duplicate';
} catch (MessageInProgress) {
    $result = 'in-progress';
} catch (RuntimeException $e) {
    $result = str_contains($e->getMessage(), 'failed') ? 'failed' : throw $e;
}

echo PHP_EOL.$result.' '.(int) ((microtime(true) - $started) * 1000).PHP_EOL;
