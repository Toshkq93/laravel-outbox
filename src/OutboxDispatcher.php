<?php

namespace Toshkq93\Outbox;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionFunction;
use Toshkq93\Outbox\Contracts\ShouldHandleOnce;
use WeakMap;

/**
 * Decorates the application's event dispatcher: ShouldBeOutboxed events are stored in the
 * outbox instead of reaching their listeners, and ShouldHandleOnce listeners of a delivered
 * message run through the inbox. Everything else is passed through untouched.
 *
 * @internal installed by the service provider; type-hint Illuminate\Contracts\Events\Dispatcher
 */
class OutboxDispatcher implements Dispatcher
{
    protected ?Outbox $outbox = null;

    protected ?Inbox $inbox = null;

    /**
     * Inbox wrappers of ShouldHandleOnce listeners => the listener as registered, so
     * introspection (Event::assertListening(), event:list) still sees the original listener.
     *
     * @var WeakMap<Closure, array{listener: string|array{0: string, 1: string}, wildcard: bool}>
     */
    protected WeakMap $handleOnce;

    /**
     * @param  Closure(): Outbox  $outboxResolver  resolved lazily, so decorating the dispatcher
     *                                             early does not open a database connection
     * @param  (Closure(): Inbox)|null  $inboxResolver
     */
    public function __construct(
        protected Dispatcher $dispatcher,
        protected Closure $outboxResolver,
        protected ?Closure $inboxResolver = null,
    ) {
        $this->handleOnce = new WeakMap;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($this->outbox()->shouldIntercept($event)) {
            $this->outbox()->record($event);

            return $halt ? null : [];
        }

        return $this->dispatcher->dispatch($event, $payload, $halt);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * @param  Closure|string|array<int, string>  $events
     * @param  Closure|string|array{0: class-string|object, 1: string}|null  $listener
     */
    public function listen($events, $listener = null)
    {
        $target = $listener !== null && ! $events instanceof Closure ? $this->handleOnceTarget($listener) : null;

        if ($target === null) {
            $this->dispatcher->listen($events, $listener);

            return;
        }

        foreach ((array) $events as $event) {
            $this->dispatcher->listen($event, $this->wrapHandleOnce($listener, $target, str_contains($event, '*')));
        }
    }

    public function hasListeners($eventName)
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Same as the framework's subscribe(), but registers through this decorator so that
     * ShouldHandleOnce listeners of a subscriber are deduplicated too.
     */
    public function subscribe($subscriber)
    {
        $subscriber = is_string($subscriber) ? Container::getInstance()->make($subscriber) : $subscriber;

        $events = $subscriber->subscribe($this);

        if (! is_array($events)) {
            return;
        }

        foreach ($events as $event => $listeners) {
            foreach (Arr::wrap($listeners) as $listener) {
                if (is_string($listener) && method_exists($subscriber, $listener)) {
                    $this->listen($event, [get_class($subscriber), $listener]);

                    continue;
                }

                $this->listen($event, $listener);
            }
        }
    }

    /**
     * @param  string  $eventName
     * @return array<int, Closure>
     */
    public function getListeners($eventName)
    {
        return array_map(function (Closure $made) {
            $original = $this->handleOnce[$this->registeredListener($made)] ?? null;

            return $original === null ? $made : $this->dispatcher->makeListener($original['listener'], $original['wildcard']);
        }, $this->dispatcher->getListeners($eventName));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function getRawListeners()
    {
        return array_map(fn (array $listeners) => array_map(
            fn ($listener) => $listener instanceof Closure && isset($this->handleOnce[$listener])
                ? $this->handleOnce[$listener]['listener']
                : $listener,
            $listeners,
        ), $this->dispatcher->getRawListeners());
    }

    /**
     * @param  string  $event
     * @param  object|array<array-key, mixed>  $payload
     */
    public function push($event, $payload = [])
    {
        $this->dispatcher->push($event, $payload);
    }

    public function flush($event)
    {
        $this->dispatcher->flush($event);
    }

    public function forget($event)
    {
        $this->dispatcher->forget($event);
    }

    public function forgetPushed()
    {
        $this->dispatcher->forgetPushed();
    }

    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Pass through everything the concrete dispatcher offers beyond the contract
     * (getListeners, setQueueResolver, ...).
     *
     * @param  array<int, mixed>  $parameters
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->dispatcher->{$method}(...$parameters);
    }

    protected function outbox(): Outbox
    {
        return $this->outbox ??= ($this->outboxResolver)();
    }

    protected function inbox(): Inbox
    {
        return $this->inbox ??= $this->inboxResolver
            ? ($this->inboxResolver)()
            : Container::getInstance()->make(Inbox::class);
    }

    /**
     * [class, method] of a synchronous ShouldHandleOnce class listener, null for anything else.
     * Queued listeners are left alone: wrapping them would stop the framework from queueing
     * them; they deduplicate with the DeduplicateMessage job middleware.
     *
     * @return array{0: class-string, 1: string}|null
     */
    protected function handleOnceTarget(mixed $listener): ?array
    {
        if (is_string($listener)) {
            $listener = Str::parseCallback($listener, 'handle');
        } elseif (! is_array($listener) || ! isset($listener[0], $listener[1]) || ! is_string($listener[0])) {
            return null;
        }

        [$class, $method] = $listener;

        if (! is_string($class) || ! class_exists($class)
            || ! is_subclass_of($class, ShouldHandleOnce::class)
            || is_subclass_of($class, ShouldQueue::class)) {
            return null;
        }

        return [$class, method_exists($class, $method) ? $method : '__invoke'];
    }

    /**
     * @param  string|array{0: string, 1: string}  $listener
     * @param  array{0: class-string, 1: string}  $target
     */
    protected function wrapHandleOnce(string|array $listener, array $target, bool $wildcard): Closure
    {
        $wrapper = function (...$arguments) use ($listener, $target, $wildcard) {
            // The framework calls wildcard listeners with (name, payload), others with the payload.
            $call = fn () => $wildcard
                ? $this->dispatcher->makeListener($listener, true)(...$arguments)
                : $this->dispatcher->makeListener($listener)('', $arguments);

            // Deduplicate only the event delivered from the outbox, not other events the
            // listener may receive while a message is being handled.
            $event = $wildcard ? ($arguments[1][0] ?? null) : ($arguments[0] ?? null);
            $message = $this->outbox()->messageFor($event);

            if ($message === null) {
                return $call();
            }

            $result = null;

            $this->inbox()->once($message, Inbox::consumerFor(...$target), function () use ($call, &$result) {
                $result = $call();
            });

            return $result;
        };

        $this->handleOnce[$wrapper] = ['listener' => $listener, 'wildcard' => $wildcard];

        return $wrapper;
    }

    /**
     * The listener a closure made by the framework's makeListener() wraps.
     */
    protected function registeredListener(Closure $made): ?Closure
    {
        $listener = (new ReflectionFunction($made))->getStaticVariables()['listener'] ?? null;

        return $listener instanceof Closure ? $listener : null;
    }
}
