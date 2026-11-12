<?php

/**
 * A separate relay process for ConcurrentRelayTest:
 *   php relay-worker.php <output-file> <worker-name>
 * DB_* environment variables select the database, as for the test suite.
 */

use Orchestra\Testbench\Foundation\Application;
use Toshkq93\Outbox\OutboxServiceProvider;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Tests\Fixtures\FilePublisher;

require __DIR__.'/../../vendor/autoload.php';

[, $path, $name] = $argv;

$app = Application::create(options: ['extra' => ['providers' => [OutboxServiceProvider::class]]]);

$app['config']->set('database.default', getenv('DB_CONNECTION'));
$app['config']->set('outbox.outbox.publisher', 'file');
$app['config']->set('outbox.outbox.publishers.file', ['driver' => FilePublisher::class, 'path' => $path, 'owner' => $name]);

$relay = $app->make(Relay::class);

// Small batches so the workers interleave; stop once nothing is due.
while (! $relay->runOnce(5, $name)->isEmpty()) {
}
