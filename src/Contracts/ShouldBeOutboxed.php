<?php

namespace Toshkq93\Outbox\Contracts;

/**
 * Marker for events that must go through the transactional outbox.
 *
 * Dispatching such an event with event() / Event::dispatch() stores it in the outbox table
 * instead of running listeners; the relay publishes it after the transaction has committed.
 *
 * Optional methods the event may define (all are looked up with method_exists):
 *
 *   outboxType(): string                     stable message type, defaults to the alias in
 *                                            config('outbox.types') or the class name
 *   outboxPayload(): array                   payload, defaults to the public properties
 *   outboxHeaders(): array                   extra headers
 *   outboxAggregate(): Model|array|null      aggregate as a model or [type, id]
 *   outboxPublisher(): ?string               publisher name from config('outbox.outbox.publishers')
 *   static fromOutboxPayload(array): static  custom reconstruction on the consumer side
 */
interface ShouldBeOutboxed {}
