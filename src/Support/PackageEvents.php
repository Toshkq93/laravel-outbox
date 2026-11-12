<?php

namespace Toshkq93\Outbox\Support;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Testing\Fakes\EventFake;

/**
 * @internal dispatches the package's monitoring events
 */
final class PackageEvents
{
    /**
     * Dispatch the event only when somebody listens: the hot paths (relay, inbox, middleware)
     * pay nothing for events nobody uses. Under Event::fake() it is always dispatched, so
     * Event::assertDispatched() works in application tests.
     *
     * The package's events do not implement ShouldBeOutboxed, so they never enter the outbox.
     *
     * @param  class-string  $class
     * @param  Closure(): object  $make
     */
    public static function dispatch(string $class, Closure $make): void
    {
        $container = Container::getInstance();

        if (! $container->bound('events')) {
            return;
        }

        $events = $container->make(Dispatcher::class);

        // Event::isFake() is public only since a later Laravel 11 release.
        if (Event::getFacadeRoot() instanceof EventFake || $events->hasListeners($class)) {
            $events->dispatch($make());
        }
    }
}
