<?php

namespace Toshkq93\Outbox\Publishers;

use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Toshkq93\Outbox\Contracts\Publisher;

/**
 * Resolves the publishers named in config('outbox.outbox.publishers').
 *
 * Each entry has a "driver": "queue" (built in), a driver registered with extend(),
 * or a class implementing Publisher (built by the container with ['config' => $config]).
 */
class PublisherManager
{
    /** @var array<string, Publisher> */
    protected array $publishers = [];

    /** @var array<string, Closure(array<string, mixed>, Container): Publisher> */
    protected array $drivers = [];

    public function __construct(protected Container $container) {}

    public function publisher(?string $name = null): Publisher
    {
        $name ??= $this->defaultName();

        return $this->publishers[$name] ??= $this->resolve($name);
    }

    /**
     * @param  Closure(array<string, mixed>, Container): Publisher  $factory
     */
    public function extend(string $driver, Closure $factory): static
    {
        $this->drivers[$driver] = $factory;
        $this->forget();

        return $this;
    }

    public function forget(?string $name = null): static
    {
        if ($name === null) {
            $this->publishers = [];
        } else {
            unset($this->publishers[$name]);
        }

        return $this;
    }

    public function defaultName(): string
    {
        return $this->container['config']->get('outbox.outbox.publisher', 'queue');
    }

    protected function resolve(string $name): Publisher
    {
        $config = $this->container['config']->get("outbox.outbox.publishers.{$name}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Outbox publisher [{$name}] is not defined in config('outbox.outbox.publishers').");
        }

        $driver = $config['driver'] ?? $name;

        if (isset($this->drivers[$driver])) {
            return ($this->drivers[$driver])($config, $this->container);
        }

        if ($driver === 'queue') {
            return new QueuePublisher(
                $this->container->make(Dispatcher::class),
                $config['connection'] ?? null,
                $config['queue'] ?? null,
            );
        }

        if (class_exists($driver) && is_subclass_of($driver, Publisher::class)) {
            return $this->container->make($driver, ['config' => $config]);
        }

        throw new InvalidArgumentException("Outbox publisher [{$name}] uses unknown driver [{$driver}].");
    }
}
