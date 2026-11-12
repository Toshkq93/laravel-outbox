<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Database connection
    |--------------------------------------------------------------------------
    |
    | Connection used for the outbox, inbox and idempotency tables. It MUST be
    | the same connection your business data is written to, otherwise the
    | outbox row and your models cannot be committed in one transaction.
    | null means the default connection.
    |
    */

    'connection' => env('OUTBOX_DB_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Message types
    |--------------------------------------------------------------------------
    |
    | Stable names for message types, alias => event class. The alias is stored
    | instead of the class name, so classes can be renamed or moved without
    | breaking messages already in the outbox or consumers in other services.
    | An event can also name itself with an outboxType() method.
    |
    */

    'types' => [
        // 'order.placed' => App\Events\OrderPlaced::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbox
    |--------------------------------------------------------------------------
    */

    'outbox' => [
        'enabled' => true,

        'table' => 'outbox_messages',

        // Throw when a message is recorded outside a database transaction:
        // without one the outbox gives no atomicity guarantee.
        'strict' => true,

        // Store ShouldBeOutboxed events dispatched with event() in the outbox
        // instead of running their listeners right away.
        'intercept_events' => true,

        // Default publisher used by the relay (see "publishers").
        'publisher' => env('OUTBOX_PUBLISHER', 'queue'),

        // "driver": "queue", a driver registered with
        // app(PublisherManager::class)->extend(), or a class implementing Publisher.
        'publishers' => [
            'queue' => [
                'driver' => 'queue',
                'connection' => env('OUTBOX_QUEUE_CONNECTION'),
                'queue' => env('OUTBOX_QUEUE'),
            ],
        ],

        'relay' => [
            // Messages claimed per iteration.
            'batch_size' => 100,

            // Seconds to sleep when the outbox is empty.
            'sleep' => 1,

            // Seconds a claimed batch stays reserved for one relay process.
            // A crashed relay's messages become available again after this.
            'lease' => 60,

            // After this many failed publish attempts a message is marked dead.
            'max_attempts' => 10,

            // Seconds to wait before attempt N+1; the last value repeats.
            'backoff' => [1, 5, 30, 120, 600],

            // Publish messages of one aggregate strictly in order: a message waits
            // while an earlier one of its aggregate is not published (a dead one
            // blocks the aggregate until outbox:retry). Lowers throughput.
            'ordered' => false,
        ],

        // outbox:prune removes published messages older than this many hours.
        'prune_after_hours' => 24 * 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbox (consumer-side deduplication)
    |--------------------------------------------------------------------------
    */

    'inbox' => [
        'enabled' => true,

        'table' => 'inbox_messages',

        // Seconds a worker waits for another one handling the same message for the same
        // consumer (PostgreSQL, MySQL, MariaDB). null waits until that transaction ends, then
        // skips the duplicate (or handles the message if the other one failed). A number makes
        // the waiting side throw MessageInProgress; DeduplicateMessage then releases the job.
        'lock_timeout' => null,

        // Run every DeliverMessage job through DeduplicateMessage: all synchronous listeners of
        // a delivered message run once, in one transaction with the inbox key. Off by default;
        // per-listener deduplication (ShouldHandleOnce) is usually the better fit.
        'deduplicate_deliveries' => false,

        // inbox:prune removes keys older than this many hours. Keep them longer than a message
        // can be redelivered (relay retries + queue retries), or late duplicates run again.
        'prune_after_hours' => 24 * 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency keys (HTTP)
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'enabled' => true,

        // "database", "cache", or a driver registered with
        // app(IdempotencyStoreManager::class)->extend().
        'driver' => env('IDEMPOTENCY_DRIVER', 'database'),

        // Table of the "database" driver (on outbox.connection).
        'table' => 'idempotency_keys',

        // Cache store of the "cache" driver; it must support atomic locks
        // (redis, database, memcached, dynamodb, file, array). null = default store.
        'cache_store' => env('IDEMPOTENCY_CACHE_STORE'),

        'header' => 'Idempotency-Key',

        // Added to replayed responses.
        'replay_header' => 'Idempotent-Replayed',

        // Reject requests without a key (400). Per route: ->middleware('idempotent:required').
        'required' => false,

        // Keys not matching are rejected with 400. Default: 1-255 visible ASCII characters.
        'key_pattern' => '/\A[\x21-\x7E]{1,255}\z/',

        // Seconds a stored response is replayed for. Per route: 'idempotent:ttl=3600'.
        'ttl' => 60 * 60 * 24,

        // Seconds a request holds its key while running; meanwhile repeats get 409. A request
        // that crashed without releasing the key frees it after this. Keep it above your
        // slowest request, or a repeat may run while the first one is still going.
        'lock' => 60,

        // Retry-After sent with 409 while the first request is still running.
        'retry_after' => 1,

        // Requests with other methods pass through untouched.
        'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

        // Responses with these statuses are not stored: the key is released and the same key
        // may be retried, e.g. after fixing a validation error or logging in. Every other
        // status below 500 is stored and replayed; 5xx are never stored.
        'release_statuses' => [401, 403, 408, 409, 419, 422, 423, 425, 429],

        // Request headers that are part of the fingerprint (besides method, path, query, body).
        'fingerprint_headers' => [],

        // Response headers that are not stored (Set-Cookie is never stored).
        'except_headers' => ['Date'],
    ],

];
