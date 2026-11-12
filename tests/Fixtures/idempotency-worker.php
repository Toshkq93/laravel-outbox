<?php

/**
 * A separate HTTP client + server process for ConcurrentIdempotencyTest:
 *   php idempotency-worker.php <log-file> <name> <hold-ms> <driver>
 * Sends POST /race with Idempotency-Key "race-key" through the HTTP kernel. The route writes a
 * ledger row, logs "<name> inside" and holds for <hold-ms>. Prints "<status> <replayed> <run-by>"
 * as the last line. The "cache" driver uses the database cache store, shared between processes.
 * DB_* environment variables select the database, as for the suite.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use Toshkq93\Outbox\OutboxServiceProvider;

// Vendor deprecation notices must not pollute the result line.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__.'/../../vendor/autoload.php';

[, $log, $name, $hold, $driver] = $argv;

$app = Application::create(options: ['extra' => ['providers' => [OutboxServiceProvider::class]]]);

$app['config']->set('database.default', getenv('DB_CONNECTION'));
$app['config']->set('outbox.idempotency.driver', $driver);
$app['config']->set('outbox.idempotency.cache_store', 'database');
$app['config']->set('cache.stores.database.lock_table', 'cache_locks');

$app['router']->post('/race', function () use ($log, $name, $hold) {
    DB::table('ledger')->insert(['entry' => $name]);
    file_put_contents($log, "{$name} inside".PHP_EOL, FILE_APPEND | LOCK_EX);
    usleep((int) $hold * 1000);

    return response()->json(['run_by' => $name], 201);
})->middleware('idempotent');

$request = Request::create('/race', 'POST', server: [
    'REMOTE_ADDR' => '10.1.1.1',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_IDEMPOTENCY_KEY' => 'race-key',
], content: '{"amount":100}');

$response = $app->make(Kernel::class)->handle($request);

echo PHP_EOL.implode(' ', [
    $response->getStatusCode(),
    $response->headers->get('Idempotent-Replayed', 'false'),
    json_decode((string) $response->getContent(), true)['run_by'] ?? '-',
]).PHP_EOL;
