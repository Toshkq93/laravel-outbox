<?php

namespace Toshkq93\Outbox\Idempotency;

use Illuminate\Support\Manager;
use Toshkq93\Outbox\Contracts\IdempotencyStore;
use Toshkq93\Outbox\Idempotency\Stores\CacheStore;
use Toshkq93\Outbox\Idempotency\Stores\DatabaseStore;

/**
 * @method IdempotencyStore driver(?string $driver = null)
 */
class IdempotencyStoreManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('outbox.idempotency.driver', 'database');
    }

    public function createDatabaseDriver(): DatabaseStore
    {
        return new DatabaseStore(
            $this->container['db']->connection($this->config->get('outbox.connection')),
            $this->config->get('outbox.idempotency.table', 'idempotency_keys'),
        );
    }

    public function createCacheDriver(): CacheStore
    {
        return new CacheStore($this->container['cache']->store($this->config->get('outbox.idempotency.cache_store')));
    }
}
