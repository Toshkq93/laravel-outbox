<?php

namespace Toshkq93\Outbox;

use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Queue;
use Illuminate\Support\ServiceProvider;
use Toshkq93\Outbox\Console\IdempotencyPruneCommand;
use Toshkq93\Outbox\Console\InboxPruneCommand;
use Toshkq93\Outbox\Console\PruneCommand;
use Toshkq93\Outbox\Console\RelayCommand;
use Toshkq93\Outbox\Console\RetryCommand;
use Toshkq93\Outbox\Console\StatusCommand;
use Toshkq93\Outbox\Contracts\IdempotencyStore;
use Toshkq93\Outbox\Contracts\PayloadSerializer;
use Toshkq93\Outbox\Contracts\Publisher;
use Toshkq93\Outbox\Http\Middleware\Idempotent;
use Toshkq93\Outbox\Idempotency\Idempotency;
use Toshkq93\Outbox\Idempotency\IdempotencyStoreManager;
use Toshkq93\Outbox\Publishers\PublisherManager;
use Toshkq93\Outbox\Relay\Relay;
use Toshkq93\Outbox\Serialization\DefaultPayloadSerializer;

class OutboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/outbox.php', 'outbox');

        $this->app->singleton(PayloadSerializer::class, DefaultPayloadSerializer::class);

        $this->app->singleton(Outbox::class, fn (Application $app) => new Outbox(
            $app['config'],
            $app['db'],
            $app->make(PayloadSerializer::class),
        ));

        $this->app->singleton(Inbox::class, fn (Application $app) => new Inbox($app['config'], $app['db']));

        $this->app->singleton(IdempotencyStoreManager::class);
        $this->app->bind(IdempotencyStore::class, fn (Application $app) => $app->make(IdempotencyStoreManager::class)->driver());
        $this->app->singleton(Idempotency::class);

        $this->app->singleton(PublisherManager::class);
        $this->app->bind(Publisher::class, fn (Application $app) => $app->make(PublisherManager::class)->publisher());
        $this->app->singleton(Relay::class);

        $this->app->extend('events', fn (Dispatcher $dispatcher, Application $app) => $dispatcher instanceof OutboxDispatcher
            ? $dispatcher
            : new OutboxDispatcher(
                $dispatcher,
                fn () => $app->make(Outbox::class),
                fn () => $app->make(Inbox::class),
            ));
    }

    public function boot(): void
    {
        $this->carryCurrentMessageOnQueuedJobs();

        $this->app['router']->aliasMiddleware('idempotent', Idempotent::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            RelayCommand::class,
            RetryCommand::class,
            PruneCommand::class,
            StatusCommand::class,
            InboxPruneCommand::class,
            IdempotencyPruneCommand::class,
        ]);

        $this->publishes([
            __DIR__.'/../config/outbox.php' => config_path('outbox.php'),
        ], 'outbox-config');

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'outbox-migrations');
    }

    /**
     * Jobs and queued listeners pushed while a message is being handled carry it in their
     * payload ("outbox_message"), so DeduplicateMessage knows the message in the worker.
     *
     * Payload hooks are static (the framework's test case clears them after every test), so
     * the outbox is resolved from the current container on every push.
     */
    protected function carryCurrentMessageOnQueuedJobs(): void
    {
        Queue::createPayloadUsing(function ($connection, $queue, array $payload) {
            $container = Container::getInstance();

            if (isset($payload['outbox_message']) || ! $container->resolved(Outbox::class)) {
                return [];
            }

            $message = $container->make(Outbox::class)->current();

            return $message === null ? [] : ['outbox_message' => $message->toArray()];
        });
    }
}
