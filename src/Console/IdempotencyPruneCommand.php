<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Idempotency\Idempotency;
use Toshkq93\Outbox\Idempotency\Stores\DatabaseStore;

#[AsCommand(name: 'idempotency:prune')]
class IdempotencyPruneCommand extends Command
{
    protected $signature = 'idempotency:prune
        {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete expired idempotency keys (database driver)';

    public function handle(Idempotency $idempotency): int
    {
        $store = $idempotency->store();

        if (! $store instanceof DatabaseStore) {
            $this->components->info('Nothing to prune: the idempotency driver expires keys itself.');

            return self::SUCCESS;
        }

        $deleted = $store->prune(chunk: max(1, (int) $this->option('chunk')));

        $this->components->info("{$deleted} expired idempotency key(s) pruned.");

        return self::SUCCESS;
    }
}
