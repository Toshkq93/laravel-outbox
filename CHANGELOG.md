# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-11

First release.

### Added

- Package skeleton: service provider, `config/outbox.php`, `outbox_messages` migration
  (publishable via `outbox-config` / `outbox-migrations` tags).
- `Outbox::record($event, headers:, aggregate:, publisher:)` stores an event in the outbox in the current
  transaction; `Outbox::recordRaw($type, $payload, ...)` stores a message without an event class.
- `ShouldBeOutboxed` marker: `event()` / `Event::dispatch()` / `Dispatchable::dispatch()` of such events
  store them in the outbox instead of running listeners (event dispatcher decorator,
  `outbox.outbox.intercept_events`). `Event::fake()` keeps working. `Outbox::bypass()` delivers directly.
- Optional event methods: `outboxType()`, `outboxPayload()`, `outboxHeaders()`, `outboxAggregate()`,
  `outboxPublisher()`, `static fromOutboxPayload()`.
- Strict mode (`outbox.outbox.strict`, on by default): `TransactionRequired` is thrown outside a
  transaction. The wrapping transaction of `RefreshDatabase` does not count, so tests catch it too.
- Message ids are UUIDv7; headers carry `correlation_id` (inherited via `Outbox::causedBy()`, or from
  `Outbox::resolveCorrelationIdUsing()`, else the message id) and `causation_id`.
- `config('outbox.types')`: stable type aliases instead of class names.
- `PayloadSerializer` contract with `DefaultPayloadSerializer`: JSON payloads (models → keys, enums,
  ISO-8601 dates with microseconds, Arrayable / JsonSerializable) and reconstruction through
  constructor named arguments; `Outbox::toEvent($message)`.
- `OutboxMessage` model and `OutboxStatus` enum.
- Relay (`Relay` service, `outbox:relay` command): claims due messages in a short transaction with
  `FOR UPDATE SKIP LOCKED` (PostgreSQL, MySQL 8, MariaDB 10.6+) plus a lease (`locked_until` / `locked_by`,
  `outbox.outbox.relay.lease`) that alone protects sqlite and frees rows of a crashed relay; publishes outside
  the transaction; on failure increments `attempts`, stores `last_error` and reschedules with
  `outbox.outbox.relay.backoff`; after `max_attempts` the message is `dead`.
- Optional per-aggregate ordering (`outbox.outbox.relay.ordered`): a message waits while an earlier message of
  its aggregate is unpublished.
- `outbox:relay` options in the style of `queue:work`: `--once`, `--stop-when-empty`, `--batch`, `--sleep`,
  `--max-time`, `--max-messages`, `--memory`; finishes the current batch on SIGTERM / SIGINT / SIGQUIT.
- `outbox:retry {id*} {--type} {--all}` (dead → pending), `outbox:prune {--hours} {--chunk}`,
  `outbox:status {--json}` (pending / due / leased / retrying / dead / published, lag of the oldest pending).
- `Publisher` contract and `PublisherManager` (`driver`: `queue`, a class, or `extend()`); per-message
  publisher via `outboxPublisher()` / `publisher:`.
- `QueuePublisher` + `DeliverMessage` job; `Outbox::deliver(Message)` rebuilds the event and dispatches it —
  only that event object skips the outbox, so `ShouldBeOutboxed` events raised by listeners are recorded, with
  the delivered message as their cause. Types without a class are dispatched as `outbox:{type}` string events
  with the `Message`. `Outbox::current()` returns the message being handled.
- `Message` value object (detached from the table row) and `OutboxMessage::toMessage()`.
- Inbox (consumer-side deduplication): `inbox_messages` migration (`message_id` + `consumer` unique, `type`,
  `processed_at`), `Inbox` service and facade. `Inbox::once($message, $consumer, $callback)` inserts the key and
  runs the handler in one transaction on the outbox connection: a duplicate is skipped (`false`), an exception
  rolls back the key with the handler's writes. Works inside an outer transaction (savepoint; a duplicate does not
  abort a PostgreSQL transaction); the handler's own unique violations are not mistaken for duplicates.
  `Inbox::processed()`, `Inbox::forget()`, `Inbox::prune()`, `InboxMessage` model.
- Concurrent duplicates: the second worker waits on the unique index until the first transaction ends, then skips
  the message (first committed) or handles it (first rolled back). `outbox.inbox.lock_timeout` (PostgreSQL, MySQL,
  MariaDB) limits the wait for the key insert only and throws `MessageInProgress`.
- `ShouldHandleOnce` listener marker: synchronous listeners of a delivered message run through `Inbox::once()`
  with the listener class as consumer (`Class@method` for other methods, or `static inboxConsumer()`); events not
  delivered from the outbox reach them as usual. Works for `Event::listen()`, wildcard listeners and subscribers;
  `Event::assertListening()` and `getRawListeners()` still see the original listener.
- `DeduplicateMessage` job middleware for queued listeners and jobs: message from `DeliverMessage`, the job's
  `inboxMessageId()`, the queued payload or `Outbox::current()`; consumer defaults to the listener / job class;
  restores `Outbox::current()` and causation in the worker; releases the job on `MessageInProgress`.
  `outbox.inbox.deduplicate_deliveries` applies it to every `DeliverMessage` (off by default).
- Jobs and queued listeners pushed while a message is being handled carry it in their payload (`outbox_message`).
- `Outbox::handling($message, $callback)` and `Outbox::messageFor($event)`.
- `inbox:prune {--hours} {--chunk}` (`outbox.inbox.prune_after_hours`, 30 days by default).
- HTTP idempotency keys (Stripe style): `Idempotent` middleware, alias `idempotent`, for
  `outbox.idempotency.methods` with the `Idempotency-Key` header. The first request runs and its response
  (status, headers without Set-Cookie / `except_headers`, body) is stored; a repeat with the same request is
  replayed with `Idempotent-Replayed: true`; the same key with another method / path / query / body → 422; a
  repeat while the first is running → 409 with `Retry-After`; missing (with `required`) or invalid key → 400.
  5xx, exceptions, streamed / file responses and `release_statuses` (401, 403, 408, 409, 419, 422, 423, 425,
  429 by default) are not stored and release the key; other 4xx are stored. Route options:
  `idempotent:required`, `idempotent:optional`, `idempotent:ttl=N`, `Idempotent::using()`.
- Fingerprint of method, path, sorted query, body (canonical JSON, form input, uploaded files by content) and
  `fingerprint_headers`; `Idempotency::fingerprintUsing()`. Scope `user:{id}` or `ip:{address}`;
  `Idempotency::resolveScopeUsing()`.
- `IdempotencyStore` contract and `IdempotencyStoreManager` (`extend()` for custom drivers):
  - `database` driver: `idempotency_keys` table with unique (scope, key), claimed with `insertOrIgnore`, a lock
    lease (`outbox.idempotency.lock`) after which a crashed request's key is taken over, `expires_at`;
  - `cache` driver: atomic cache lock + cache entry (`outbox.idempotency.cache_store`, must support locks).
- `idempotency:prune {--chunk}` deletes expired keys of the database driver.
- Monitoring events in `Toshkq93\Outbox\Events`: `MessageRecorded`, `MessagePublished`, `MessagePublishFailed`
  (`attempt`, `exception`, `willRetry`, `retryAt`), `MessageDead`, `RelayBatchProcessed` (result, relay, duration),
  `MessageHandled`, `DuplicateMessageSkipped`, `IdempotentRequestReplayed`, `IdempotencyKeyConflict`. Built and
  dispatched only when something listens (always under `Event::fake()`); never outboxed.
- CI: sqlite matrix (PHP 8.2–8.4 × Laravel 11–13, lowest and stable), PostgreSQL / MySQL / MariaDB jobs, PHPStan
  (Larastan, level 6) and Pint.

[Unreleased]: https://github.com/toshkq93/laravel-outbox/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/toshkq93/laravel-outbox/releases/tag/v0.1.0
