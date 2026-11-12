<?php

namespace Toshkq93\Outbox\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Toshkq93\Outbox\OutboxServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [OutboxServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // DB_CONNECTION=pgsql|mysql (with DB_HOST, DB_PORT, ...) runs the suite against a real server;
        // anything else falls back to Testbench's in-memory sqlite "testing" connection.
        $connection = env('DB_CONNECTION', 'testing');

        $app['config']->set('database.default', in_array($connection, ['pgsql', 'mysql', 'mariadb'], true) ? $connection : 'testing');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }
}
