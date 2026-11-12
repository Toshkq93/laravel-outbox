# Laravel Outbox

[![tests](https://github.com/toshkq93/laravel-outbox/actions/workflows/tests.yml/badge.svg)](https://github.com/toshkq93/laravel-outbox/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/toshkq93/laravel-outbox.svg)](https://packagist.org/packages/toshkq93/laravel-outbox)
[![License](https://img.shields.io/packagist/l/toshkq93/laravel-outbox.svg)](LICENSE)

Reliable messaging for Laravel in one package: **transactional outbox**, a **relay** that publishes with retries,
**inbox** deduplication for consumers and Stripe-style **idempotency keys** for HTTP APIs.

```php
DB::transaction(function () {
    $order = Order::create([...]);

    OrderPlaced::dispatch($order);   // stored with the order, published after the commit, never lost
});
```

- [Why](#why)
- [Quick start](#quick-start)
- [Outbox: recording messages](#outbox-recording-messages)
- [Relay: publishing](#relay-publishing)
- [Inbox: consuming exactly once](#inbox-consuming-exactly-once)
- [Idempotency keys for APIs](#idempotency-keys-for-apis)
- [Events and monitoring](#events-and-monitoring)
- [Running in production](#running-in-production)
- [Guarantees and limits](#guarantees-and-limits)
- [Testing your application](#testing-your-application)
- [FAQ](#faq)
- [Alternatives](#alternatives)

## Why

Most applications write to the database and then tell someone about it: dispatch a job, publish to a broker, call
a webhook. These are two separate writes, and any crash, timeout or deploy between them leaves the system
inconsistent:

- the transaction commits, the process dies before the job is queued → **the message is lost**;
- the job is queued, the transaction rolls back → **a message about something that never happened**;
- the job runs twice (worker crash, retry, broker redelivery) → **the side effect happens twice**;
- the client retries a `POST` after a timeout → **a second order, a second charge**.

This package closes each gap with a well-known pattern:

| Problem                               | Pattern                                                               |
|---------------------------------------|-----------------------------------------------------------------------|
| lost or phantom messages              | **outbox**: the message is a row in the same transaction as the data   |
| getting messages out reliably         | **relay**: `SKIP LOCKED` workers, leases, backoff, dead messages       |
| duplicates on the consumer side       | **inbox**: a processed-key in the same transaction as the handler     |
| duplicate HTTP requests               | **idempotency keys**: replay the stored response                      |

Each module can be used on its own and switched off in the config.

Requirements: PHP 8.2+, Laravel 11, 12 or 13; PostgreSQL, MySQL 8, MariaDB 10.6+ or SQLite.

## Quick start

**1. Install** and create the tables (`outbox_messages`, `inbox_messages`, `idempotency_keys`):

```bash
composer require toshkq93/laravel-outbox
php artisan vendor:publish --tag=outbox-config
php artisan vendor:publish --tag=outbox-migrations
php artisan migrate
```

**2. Mark an event** and dispatch it inside your transaction:

```php
use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;

class OrderPlaced implements ShouldBeOutboxed
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}

DB::transaction(function () use ($request) {
    $order = Order::create($request->validated());

    OrderPlaced::dispatch($order);   // a row in outbox_messages; listeners do not run yet
});
```

**3. Make listeners idempotent** (they may receive a message twice):

```php
use Toshkq93\Outbox\Contracts\ShouldHandleOnce;

class CreateInvoice implements ShouldHandleOnce
{
    public function handle(OrderPlaced $event): void
    {
        Invoice::create(['order_id' => $event->order->id]);   // once per message
    }
}
```

**4. Run the relay** next to your queue worker. It publishes stored messages to the queue; the worker rebuilds
the event and runs your listeners:

```bash
php artisan outbox:relay
php artisan queue:work
```

**5. Protect your API** from client retries:

```php
Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent');
```

That's it. Schedule the cleanup commands (see [Running in production](#running-in-production)) and read on for
the details.

## Outbox: recording messages

Mark an event with `ShouldBeOutboxed` and dispatch it as usual, inside the transaction that changes your data:

```php
use Toshkq93\Outbox\Contracts\ShouldBeOutboxed;

class OrderPlaced implements ShouldBeOutboxed
{
    use Dispatchable;

    public function __construct(public Order $order, public OrderStatus $status) {}

    public function outboxAggregate(): Order   // optional: groups messages per aggregate
    {
        return $this->order;
    }
}

DB::transaction(function () {
    $order = Order::create([...]);

    OrderPlaced::dispatch($order, OrderStatus::New);   // stored in outbox_messages, listeners do not run yet
});
```

Or call the outbox directly, also for messages without an event class:

```php
use Toshkq93\Outbox\Facades\Outbox;

Outbox::record(new OrderPlaced($order, OrderStatus::New), headers: ['tenant' => 'acme']);
Outbox::recordRaw('order.shipped', ['order' => $order->id], aggregate: $order);
```

- **Strict mode** (default): recording outside a transaction throws `TransactionRequired`, because the
  message would no longer be atomic with your data. The wrapping transaction of `RefreshDatabase` does not
  count, so a missing `DB::transaction()` fails in your tests as well.
- **Payload**: public properties (or `outboxPayload()`) as JSON. Models are stored as their key, enums as
  their value, dates as ISO-8601. The consumer side rebuilds the event through its constructor, re-fetching
  models; define `static fromOutboxPayload(array $payload)` for anything custom. Key order inside the
  payload is not preserved on MySQL (JSON column).
- **Types**: the class name is stored by default; map stable aliases in `config('outbox.types')` or define
  `outboxType()` so renaming a class does not break stored messages or other services.
- **Headers**: every message has a UUIDv7 `message_id`, a `correlation_id` and, when recorded while handling
  another message (`Outbox::causedBy()`), a `causation_id`.

## Relay: publishing

Run the relay next to your queue workers (Supervisor, systemd, Kubernetes):

```bash
php artisan outbox:relay                 # loop forever, sleep when idle
php artisan outbox:relay --max-time=3600 # let the process manager restart it hourly
php artisan outbox:relay --once          # a single batch, e.g. from the scheduler
```

How it works:

1. **Claim.** A short transaction selects due messages `FOR UPDATE SKIP LOCKED` and leases them to this
   process (`locked_until`, `locked_by`). Parallel relays never wait for or take each other's rows. A crashed
   relay's lease simply expires (`outbox.outbox.relay.lease`) and the messages are picked up again.
   SQLite has no `SKIP LOCKED`; there the lease alone keeps relays apart.
2. **Publish** each message outside any transaction.
3. **Mark** it published, or store the error and retry later with backoff (`outbox.outbox.relay.backoff`).
   After `max_attempts` the message is **dead** and waits for `php artisan outbox:retry`.

Delivery is **at-least-once**: a relay that dies after publishing but before marking will publish the message
again, so consumers must deduplicate on `message_id` (see [the inbox](#inbox-consuming-exactly-once)).

With `outbox.outbox.relay.ordered` enabled, a message waits while an earlier message of the same aggregate is
not yet published, so consumers see each aggregate's messages in order. A dead message blocks its aggregate
until it is retried.

```bash
php artisan outbox:status          # pending / due / leased / retrying / dead, lag of the oldest pending
php artisan outbox:status --json   # for monitoring
php artisan outbox:retry --all     # or: outbox:retry <message-id> ... / --type=order.placed
php artisan outbox:prune --hours=168
```

```php
// routes/console.php
Schedule::command('outbox:prune')->daily();
```

### Publishers

The default `queue` publisher pushes a `DeliverMessage` job to a Laravel queue (`outbox.outbox.publishers.queue`:
`connection`, `queue`). The worker rebuilds the event and dispatches it to your listeners. Only that event skips
the outbox: `ShouldBeOutboxed` events dispatched by the listeners are recorded as usual, with the delivered
message as their `causation_id`. Inside a listener `Outbox::current()` returns the `Message` being handled.

Message types without an event class (`recordRaw()`, other services) are dispatched as string events:

```php
Event::listen('outbox:order.shipped', function (Message $message) { ... });
```

Your own transport (Kafka, RabbitMQ, SNS, HTTP, ...) implements `Publisher`:

```php
class KafkaPublisher implements Publisher
{
    public function __construct(private array $config) {}

    public function publish(Message $message): void   // throw to retry with backoff
    {
        // $message->messageId, type, payload, headers, aggregateType, aggregateId
    }
}

// config/outbox.php
'publishers' => [
    'queue' => ['driver' => 'queue'],
    'kafka' => ['driver' => App\Outbox\KafkaPublisher::class, 'topic' => 'orders'],
],

// or register a driver: app(PublisherManager::class)->extend('kafka', fn (array $config, $app) => ...);
```

`outbox.outbox.publisher` is the default; an event can pick another with `outboxPublisher()`.

## Inbox: consuming exactly once

Delivery is at-least-once, so the same message can arrive twice (relay crash, queue retry, redelivery from a
broker). The inbox records which consumer handled which message, **in the same transaction as the handler's
writes**, so for everything stored in your database a message is handled exactly once.

### Idempotent listeners

Mark a listener with `ShouldHandleOnce`:

```php
use Toshkq93\Outbox\Contracts\ShouldHandleOnce;

class CreateInvoice implements ShouldHandleOnce
{
    public function handle(OrderPlaced $event): void
    {
        Invoice::create([...]);   // runs once per message, even if OrderPlaced is delivered twice
    }
}
```

When the event is delivered from the outbox, the listener runs inside `Inbox::once()`: message id from
`Outbox::current()`, consumer = the listener class (`Class@method` for a method other than `handle`). Define
`public static function inboxConsumer(): string` to keep the name stable across renames. If the listener throws,
its writes and the inbox key are rolled back together and the redelivery runs it again, while listeners that
already succeeded are skipped. The same event dispatched with plain `event()` reaches the listener as usual.

Queued listeners run in their own job; add the middleware instead:

```php
use Toshkq93\Outbox\Jobs\Middleware\DeduplicateMessage;

class SendInvoiceMail implements ShouldQueue
{
    public function middleware(): array
    {
        return [new DeduplicateMessage];   // consumer = SendInvoiceMail::class
    }
}
```

Jobs and queued listeners pushed while a message is handled carry that message in their payload, so in the
worker `DeduplicateMessage` knows it, and `Outbox::current()` / causation of new events work as in a
synchronous listener. To deduplicate whole deliveries (all synchronous listeners of a message in one
transaction) enable `outbox.inbox.deduplicate_deliveries`.

### Direct use

For anything else, e.g. messages consumed from another system:

```php
use Toshkq93\Outbox\Facades\Inbox;

$handled = Inbox::once($messageId, 'payments.capture', function () use ($payload) {
    Payment::capture($payload);
});   // false: already handled, the callback did not run

Inbox::processed($messageId, 'payments.capture');
Inbox::forget($messageId, 'payments.capture');   // allow handling it again
```

A job can name its message for the middleware with `inboxMessageId(): ?string`.

Rules of thumb:

- The handler's writes must go to the outbox connection (`outbox.connection`); otherwise they are not in the
  inbox transaction.
- Side effects outside the database (HTTP calls, emails) are not rolled back. Make them idempotent on their own
  (e.g. pass the message id as an idempotency key) or record a message in the outbox instead.
- Called inside your own transaction, `once()` uses a savepoint and the key commits with your transaction.

### Two workers, one message

The key is protected by a unique index on `(message_id, consumer)`. When two workers get the same message at the
same time, the second insert **waits** until the first transaction ends:

- the first commits → the second sees a duplicate and skips the message;
- the first fails and rolls back → the second handles the message.

The second worker is busy while it waits. To give up instead, set `outbox.inbox.lock_timeout` (seconds;
PostgreSQL, MySQL, MariaDB): after it the waiting side throws `MessageInProgress`, which `DeduplicateMessage`
turns into `$job->release()` for a later retry. The limit applies only to the key insert, not to the handler's
own queries.

```php
// routes/console.php
Schedule::command('inbox:prune')->daily();   // keys older than outbox.inbox.prune_after_hours (30 days)
```

Keep keys longer than a message can be redelivered (relay backoff + queue retries), or a late duplicate runs again.

## Idempotency keys for APIs

Clients retry: timeouts, flaky networks, double clicks. With an `Idempotency-Key` header a retried `POST` returns
the original response instead of creating a second order or charging twice (the scheme Stripe uses).

```php
Route::post('/orders', [OrderController::class, 'store'])->middleware('idempotent');
Route::post('/payments', ...)->middleware('idempotent:required,ttl=3600');
Route::post('/payments', ...)->middleware(Idempotent::using(required: true, ttl: 3600));
```

| Request                                                | Response                                               |
|--------------------------------------------------------|--------------------------------------------------------|
| first request with a key                               | runs; the response is stored                           |
| same key, same request                                 | the stored response + `Idempotent-Replayed: true`      |
| same key, different method / path / query / body       | `422`                                                  |
| same key while the first request is still running      | `409` + `Retry-After`                                  |
| no key (`required`) or a key not matching `key_pattern` | `400`                                                  |
| no key (optional), or a `GET` / other method           | runs as usual                                          |

Errors are `HttpException`s, rendered by your exception handler (`{"message": ...}` for JSON requests).

What is stored:

- **2xx, 3xx and most 4xx** are stored and replayed: the request did run (e.g. a `404` for an already deleted
  resource), and a retry must not run it again.
- **Not stored, key released**: `5xx`, exceptions, and `release_statuses` (401, 403, 408, 409, 419, 422, 423, 425,
  429): nothing happened yet, so the client may log in, fix a validation error or wait out a rate limit and retry
  with **the same key**. Because the fingerprint changes when the body is fixed, a stored `422` would otherwise
  turn every corrected retry into a mismatch.
- Streamed and file responses are never stored (the key is released): avoid the middleware on such routes.
- `Set-Cookie` and `except_headers` (`Date`) are not stored; the replay passes through your middleware again, so
  session cookies are set as usual.

**Fingerprint**: method, path, sorted query and body. JSON is compared canonically (key order and whitespace do
not matter), forms by input, uploads by file content. Add headers with `fingerprint_headers`, or replace it:

```php
Idempotency::fingerprintUsing(fn (Request $request) => $request->path().json_encode($request->only('amount')));
```

**Scope**: keys belong to `user:{id}` when authenticated, otherwise `ip:{address}`, so clients cannot replay each
other's responses. For tenants or API tokens: `Idempotency::resolveScopeUsing(fn (Request $r) => 'tenant:'.$r->header('X-Tenant'))`.

**Stores** (`outbox.idempotency.driver`):

- `database` (default): `idempotency_keys` on the outbox connection. The key row is committed before the
  request runs, so parallel requests see it at once; the unique `(scope, key)` index decides who runs. Schedule
  `php artisan idempotency:prune` to delete expired keys.
- `cache`: an atomic cache lock plus a cache entry (`outbox.idempotency.cache_store`; Redis, database, Memcached,
  DynamoDB, file or array). Expiry is left to the cache.
- Your own: implement `IdempotencyStore` and register it with `app(IdempotencyStoreManager::class)->extend('name', fn ($app) => ...)`.

**Timing**: a running request holds its key for `lock` seconds (default 60). If it crashes without finishing, the
key frees itself after that and a retry runs. Keep `lock` above your slowest request, otherwise a retry may run
while the first request is still going. Stored responses are replayed for `ttl` seconds (default 24 h, per route
`ttl=`).

The key protects the HTTP response, not the work: if the process dies after committing your data but before the
response is stored, a retry after `lock` runs the request again. Combine with the outbox / inbox and unique
constraints for the parts that must never repeat.

## Events and monitoring

The package dispatches plain Laravel events. They are only built when something listens (or under
`Event::fake()`), so unused events cost nothing, and they never go through the outbox themselves.

| Event                                   | When                                                       | Payload                                                        |
|-----------------------------------------|------------------------------------------------------------|----------------------------------------------------------------|
| `MessageRecorded`                       | a message is stored (inside your transaction)              | `message` (`OutboxMessage`)                                    |
| `MessagePublished`                      | the relay handed a message to its publisher                | `message`, `publisher`, `attempt`                              |
| `MessagePublishFailed`                  | publishing failed                                          | `message`, `publisher`, `exception`, `attempt`, `willRetry`, `retryAt` |
| `MessageDead`                           | a message ran out of attempts                              | `message`, `publisher`, `exception`, `attempts`                |
| `RelayBatchProcessed`                   | the relay finished a batch (empty ones too: a heartbeat)   | `result` (`claimed`, `published`, `failed`, `dead`), `relay`, `durationMs` |
| `MessageHandled`                        | `Inbox::once()` ran the handler                            | `messageId`, `consumer`, `type`                                |
| `DuplicateMessageSkipped`               | `Inbox::once()` skipped a duplicate                        | `messageId`, `consumer`, `type`                                |
| `IdempotentRequestReplayed`             | a stored response was replayed                             | `request`, `key`, `scope`, `status`                            |
| `IdempotencyKeyConflict`                | a request got 409 (`in_progress`) or 422 (`mismatch`)      | `request`, `key`, `scope`, `reason`, `status`                  |

All live in `Toshkq93\Outbox\Events`. For example, alerting and metrics:

```php
Event::listen(MessageDead::class, function (MessageDead $event) {
    Log::critical('Outbox message is dead', [
        'id' => $event->message->messageId,
        'type' => $event->message->type,
        'error' => $event->exception->getMessage(),
    ]);
});

Event::listen(RelayBatchProcessed::class, fn ($event) => Metrics::histogram('outbox.batch_ms', $event->durationMs));
```

For dashboards poll `php artisan outbox:status --json`:

```json
{"pending": 12, "due": 3, "leased": 9, "retrying": 1, "dead": 0, "published": 48210, "lag_seconds": 2}
```

Alert on `dead > 0` and on `lag_seconds` growing (the age of the oldest unpublished message: the relay is down or
cannot keep up).

## Running in production

**The relay** is a long-running process like `queue:work`. Run one or more per application; they share the work
safely (`SKIP LOCKED` + leases). It finishes its batch on `SIGTERM`, so deploys do not interrupt a publish.

Supervisor:

```ini
[program:outbox-relay]
command=php /var/www/artisan outbox:relay --max-time=3600
numprocs=2
process_name=%(program_name)s_%(process_num)02d
autostart=true
autorestart=true
stopwaitsecs=60
stopsignal=TERM
```

Kubernetes (a Deployment next to your workers):

```yaml
containers:
  - name: outbox-relay
    image: my-app:latest
    command: ["php", "artisan", "outbox:relay", "--max-time=3600"]
terminationGracePeriodSeconds: 60   # SIGTERM lets the relay finish its batch
```

Without a long-running process, the scheduler can drive it: `Schedule::command('outbox:relay --stop-when-empty')->everyMinute()->withoutOverlapping()`
(messages then wait up to a minute).

**Cleanup** (`routes/console.php`):

```php
Schedule::command('outbox:prune')->daily();        // published messages older than 7 days
Schedule::command('inbox:prune')->daily();         // inbox keys older than 30 days
Schedule::command('idempotency:prune')->hourly();  // expired keys (database driver)
```

**Configuration** lives in `config/outbox.php`, one section per module (`outbox`, `inbox`, `idempotency`), each
with an `enabled` switch. All tables use `outbox.connection`, which must be the connection your business data is
written to.

**Dead messages**: fix the cause, then `php artisan outbox:retry --all` (or by id / `--type`).

## Guarantees and limits

- **No lost and no phantom messages**: a message is stored if and only if your transaction commits (strict mode
  rejects recording outside a transaction).
- **At-least-once delivery**: a relay that dies between publishing and marking publishes the message again; a
  queue job may be retried. Consumers must expect duplicates.
- **Exactly-once effects, for the database only**: the inbox commits the processed-key with the handler's writes,
  so database changes happen once. HTTP calls, emails and other external effects are not rolled back; make them
  idempotent themselves (pass the `message_id` as an idempotency key) or turn them into outbox messages.
- **Ordering**: by default messages are published oldest first, but retries and parallel relays can reorder
  them. `outbox.outbox.relay.ordered` keeps each aggregate's messages in order at some throughput cost; a dead
  message blocks its aggregate until retried. The queue publisher's workers may still run jobs in parallel; use a
  single worker (or a broker with per-key ordering) when the consumer depends on order.
- **Idempotency keys** protect the HTTP response: if the process dies after your data is committed but before the
  response is stored, a retry after `lock` seconds runs the request again.
- **Same database**: the outbox atomicity needs your data and the outbox table on one connection.

## Testing your application

Outboxed events are stored, not handled, in your tests too, and strict mode catches a missing `DB::transaction()`
even inside `RefreshDatabase`. Typical assertions:

```php
// the event was recorded with the order
$this->assertDatabaseHas('outbox_messages', ['type' => OrderPlaced::class, 'status' => 'pending']);

// Event::fake() captures outboxed events as usual (nothing is stored)
Event::fake([OrderPlaced::class]);
$this->post('/orders', [...]);
Event::assertDispatched(OrderPlaced::class);

// end to end: publish what was recorded and run the listeners
config(['queue.default' => 'sync']);
app(\Toshkq93\Outbox\Relay\Relay::class)->runOnce();

// run listeners right away, without the outbox
Outbox::bypass(fn () => OrderPlaced::dispatch($order));
```

Test a consumer twice with the same message to prove it is idempotent:

```php
$message = new \Toshkq93\Outbox\Message('msg-1', OrderPlaced::class, ['order' => $order->id]);
Outbox::deliver($message);
Outbox::deliver($message);
$this->assertSame(1, Invoice::count());
```

## FAQ

**Why not just `ShouldDispatchAfterCommit` / `afterCommit()` jobs?** They avoid phantom messages but not lost
ones: if the process dies or the queue is unreachable right after the commit, the job is gone. The outbox makes
the message part of the commit.

**I use the database queue driver on the same connection. Do I need this?** Pushing a job inside your transaction
is then already atomic: that is an outbox in itself. This package adds what that setup lacks: a broker-agnostic
publisher (Kafka, RabbitMQ, SNS, HTTP), stable message types and ids for other services, per-aggregate ordering,
dead messages, correlation / causation ids, consumer deduplication and idempotency keys.

**Do listeners still run in my application?** Yes, with the default `queue` publisher: the queue worker rebuilds
the event and dispatches it to your listeners. With your own publisher, the messages go to other services.

**Does it work with Horizon?** Yes: the relay pushes ordinary `DeliverMessage` jobs (tagged `outbox`,
`outbox:{type}`, `message:{id}`).

**How big can a payload be?** It is a JSON column; keep messages small and send ids rather than whole models
(models are stored as their key by default).

**What happens to messages recorded on a read replica / another connection?** Configure `outbox.connection` to
the connection you write your data to; recording on another connection breaks atomicity.

**Can I use only the idempotency middleware (or only the inbox)?** Yes. The modules are independent; disable the
others with their `enabled` switch.

## Alternatives

- **`afterCommit` jobs and events** (built into Laravel): fine when losing a message on a crash is acceptable.
- **The database queue driver inside your transaction**: atomic, but Laravel-queue only; see the FAQ.
- **Event sourcing** (e.g. spatie/laravel-event-sourcing): stores events as the source of truth, a much larger
  change of architecture. An outbox keeps your existing models and only makes their notifications reliable.
- **Debezium / change data capture**: publishes by tailing the database log; no polling, but extra
  infrastructure (Kafka Connect) and operations. The relay here needs nothing but the database.
- **Broker transactions** (e.g. Kafka transactions): atomic within the broker, but cannot span your database.

## Contributing

```bash
composer test                       # in-memory sqlite
docker compose up -d                # PostgreSQL, MySQL, MariaDB; see compose.yaml for the env vars
                                    # (includes multi-process relay, inbox and idempotency race tests)
composer require --dev larastan/larastan laravel/pint   # not in require-dev, see CI
composer analyse && vendor/bin/pint --test
```

## License

MIT. See [LICENSE](LICENSE).
