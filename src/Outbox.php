<?php

namespace Toshkq93\Outbox;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Toshkq93\Outbox\Contracts\PayloadSerializer;
use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;
use Toshkq93\Outbox\Events\MessageRecorded;
use Toshkq93\Outbox\Exceptions\TransactionRequired;
use Toshkq93\Outbox\Exceptions\UnknownMessageType;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\Support\PackageEvents;

class Outbox
{
    /** Events dispatched while this is above zero skip the outbox. */
    protected int $bypassing = 0;

    /** @var list<array{message_id: string, correlation_id: string}> */
    protected array $causes = [];

    protected ?Closure $correlationIdResolver = null;

    /**
     * Messages being handled, innermost last; "event" is the object dispatched for the
     * message (the rebuilt event, or the Message itself for raw types), null when only the
     * context was restored (e.g. in a queued listener).
     *
     * @var list<array{message: Message, event: ?object}>
     */
    protected array $handling = [];

    public function __construct(
        protected Config $config,
        protected DatabaseManager $db,
        protected PayloadSerializer $serializer,
    ) {}

    /**
     * Store an event in the outbox, in the current database transaction.
     *
     * @param  array<string, mixed>  $headers
     * @param  Model|array{0: string, 1: string|int}|null  $aggregate  model or [type, id]
     */
    public function record(object $event, array $headers = [], Model|array|null $aggregate = null, ?string $publisher = null): OutboxMessage
    {
        return $this->store(
            type: $this->typeOf($event),
            payload: $this->serializer->serialize($event),
            headers: array_merge($this->call($event, 'outboxHeaders') ?? [], $headers),
            aggregate: $aggregate ?? $this->call($event, 'outboxAggregate'),
            publisher: $publisher ?? $this->call($event, 'outboxPublisher'),
        );
    }

    /**
     * Store a raw message (no event class) in the outbox, e.g. for consumers in other services.
     *
     * @param  array<array-key, mixed>  $payload  JSON-safe data
     * @param  array<string, mixed>  $headers
     * @param  Model|array{0: string, 1: string|int}|null  $aggregate  model or [type, id]
     */
    public function recordRaw(string $type, array $payload, array $headers = [], Model|array|null $aggregate = null, ?string $publisher = null): OutboxMessage
    {
        return $this->store($type, $payload, $headers, $aggregate, $publisher);
    }

    /**
     * Rebuild the event stored in a message.
     */
    public function toEvent(OutboxMessage $message): object
    {
        return $this->serializer->deserialize($this->classOf($message->type), $message->payload);
    }

    /**
     * Run a callback with event() dispatching ShouldBeOutboxed events straight to their listeners.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function bypass(callable $callback): mixed
    {
        $this->bypassing++;

        try {
            return $callback();
        } finally {
            $this->bypassing--;
        }
    }

    /**
     * Hand a published message to this application's listeners (used by DeliverMessage).
     *
     * The event is rebuilt and dispatched; only this event object skips the outbox, so
     * ShouldBeOutboxed events raised by listeners are recorded, caused by the message.
     * Types without a class are dispatched as "outbox:{type}" string events with the Message.
     */
    public function deliver(Message $message): void
    {
        $class = $this->types()[$message->type] ?? $message->type;

        if (class_exists($class)) {
            $event = $this->serializer->deserialize($class, $message->payload);

            $this->handling($message, fn () => $this->events()->dispatch($event), $event);

            return;
        }

        $this->handling(
            $message,
            fn () => $this->events()->dispatch('outbox:'.$message->type, [$message]),
            $message,
        );
    }

    /**
     * Run a callback in the context of a message: Outbox::current() returns it and messages
     * recorded inside are caused by it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  object|null  $event  the object dispatched for the message, if any
     * @return T
     */
    public function handling(Message $message, callable $callback, ?object $event = null): mixed
    {
        $this->handling[] = ['message' => $message, 'event' => $event];

        try {
            return $this->causedBy($message->messageId, $message->correlationId(), $callback);
        } finally {
            array_pop($this->handling);
        }
    }

    /**
     * The message being handled, e.g. inside a listener of a delivered event.
     */
    public function current(): ?Message
    {
        return $this->handling === [] ? null : $this->handling[array_key_last($this->handling)]['message'];
    }

    /**
     * The message an event object was delivered for, if it is being delivered right now.
     */
    public function messageFor(mixed $event): ?Message
    {
        if (! is_object($event)) {
            return null;
        }

        foreach (array_reverse($this->handling) as $entry) {
            if ($entry['event'] === $event) {
                return $entry['message'];
            }
        }

        return null;
    }

    public function isBypassing(): bool
    {
        return $this->bypassing > 0;
    }

    /**
     * Run a callback while handling a message: messages recorded inside inherit its
     * correlation id and get its id as their causation id.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function causedBy(OutboxMessage|string $messageId, ?string $correlationId, callable $callback): mixed
    {
        if ($messageId instanceof OutboxMessage) {
            $correlationId ??= $messageId->correlationId();
            $messageId = $messageId->message_id;
        }

        $this->causes[] = ['message_id' => $messageId, 'correlation_id' => $correlationId ?? $messageId];

        try {
            return $callback();
        } finally {
            array_pop($this->causes);
        }
    }

    /**
     * Resolve the correlation id for messages recorded outside causedBy(), e.g. from a request header.
     * Returning null falls back to the message's own id.
     *
     * @param  (Closure(): ?string)|null  $resolver
     */
    public function resolveCorrelationIdUsing(?Closure $resolver): void
    {
        $this->correlationIdResolver = $resolver;
    }

    /**
     * @internal used by OutboxDispatcher
     */
    public function shouldIntercept(mixed $event): bool
    {
        return $event instanceof ShouldBeOutboxed
            && ! $this->isBypassing()
            && $this->messageFor($event) === null
            && $this->config->get('outbox.outbox.enabled', true)
            && $this->config->get('outbox.outbox.intercept_events', true);
    }

    public function connection(): Connection
    {
        return $this->db->connection($this->config->get('outbox.connection'));
    }

    /**
     * Whether the outbox connection has an open transaction of the application's own.
     *
     * Asks Laravel itself: an afterCommit() callback runs immediately when there is no
     * transaction it would wait for. The wrapping transaction of RefreshDatabase /
     * DatabaseTransactions does not count, so strict mode catches a missing transaction
     * in tests too. Inside a transaction the probe stays queued as a no-op.
     */
    public function inTransaction(): bool
    {
        $connection = $this->connection();

        if ($connection->transactionLevel() === 0) {
            return false;
        }

        $ranImmediately = false;

        $connection->afterCommit(function () use (&$ranImmediately) {
            $ranImmediately = true;
        });

        return ! $ranImmediately;
    }

    public function typeOf(object $event): string
    {
        return $this->call($event, 'outboxType')
            ?? (array_search($event::class, $this->types(), true) ?: $event::class);
    }

    /**
     * @return class-string
     */
    public function classOf(string $type): string
    {
        $class = $this->types()[$type] ?? $type;

        if (! class_exists($class)) {
            throw UnknownMessageType::make($type);
        }

        return $class;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @param  array<string, mixed>  $headers
     * @param  Model|array{0: string, 1: string|int}|null  $aggregate
     */
    protected function store(string $type, array $payload, array $headers, Model|array|null $aggregate, ?string $publisher): OutboxMessage
    {
        if ($this->config->get('outbox.outbox.strict', true) && ! $this->inTransaction()) {
            throw TransactionRequired::forType($type, $this->config->get('outbox.connection'));
        }

        [$aggregateType, $aggregateId] = $this->aggregate($aggregate);

        $messageId = Uuid::uuid7()->toString();
        $now = Date::now();

        $message = OutboxMessage::query()->create([
            'message_id' => $messageId,
            'type' => $type,
            'payload' => $payload,
            'headers' => $this->headers($messageId, $headers),
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'publisher' => $publisher,
            'status' => OutboxStatus::Pending,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
        ]);

        PackageEvents::dispatch(MessageRecorded::class, fn () => new MessageRecorded($message));

        return $message;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    protected function headers(string $messageId, array $headers): array
    {
        $cause = end($this->causes) ?: null;

        $defaults = [
            'correlation_id' => $cause['correlation_id']
                ?? ($this->correlationIdResolver ? ($this->correlationIdResolver)() : null)
                ?? $messageId,
            'causation_id' => $cause['message_id'] ?? null,
        ];

        return array_filter(array_merge($defaults, $headers), fn ($value) => $value !== null);
    }

    /**
     * @param  Model|array<array-key, mixed>|null  $aggregate  validated here: outboxAggregate() may return anything
     * @return array{0: ?string, 1: ?string}
     */
    protected function aggregate(Model|array|null $aggregate): array
    {
        if ($aggregate === null) {
            return [null, null];
        }

        if ($aggregate instanceof Model) {
            return [$aggregate->getMorphClass(), (string) $aggregate->getKey()];
        }

        if (! array_is_list($aggregate) || count($aggregate) !== 2) {
            throw new InvalidArgumentException('Outbox aggregate must be a model or a [type, id] pair.');
        }

        return [(string) $aggregate[0], (string) $aggregate[1]];
    }

    /**
     * @return array<string, class-string>
     */
    protected function types(): array
    {
        return $this->config->get('outbox.types', []);
    }

    protected function events(): Dispatcher
    {
        return Container::getInstance()->make('events');
    }

    protected function call(object $event, string $method): mixed
    {
        return method_exists($event, $method) ? $event->{$method}() : null;
    }
}
