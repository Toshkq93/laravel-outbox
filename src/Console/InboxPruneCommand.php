<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Inbox;

#[AsCommand(name: 'inbox:prune')]
class InboxPruneCommand extends Command
{
    protected $signature = 'inbox:prune
        {--hours= : Delete keys of messages handled more than this many hours ago (default: outbox.inbox.prune_after_hours)}
        {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete old inbox deduplication keys';

    public function handle(Inbox $inbox): int
    {
        $hours = (int) ($this->option('hours') ?? config('outbox.inbox.prune_after_hours', 24 * 30));

        $deleted = $inbox->prune(Date::now()->subHours($hours), max(1, (int) $this->option('chunk')));

        $this->components->info("{$deleted} inbox key(s) pruned.");

        return self::SUCCESS;
    }
}
