<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;

#[AsCommand(name: 'outbox:prune')]
class PruneCommand extends Command
{
    protected $signature = 'outbox:prune
        {--hours= : Delete messages published more than this many hours ago (default: outbox.outbox.prune_after_hours)}
        {--chunk=1000 : Rows deleted per query}';

    protected $description = 'Delete published outbox messages';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?? config('outbox.outbox.prune_after_hours', 24 * 7));
        $chunk = max(1, (int) $this->option('chunk'));
        $before = Date::now()->subHours($hours)->format((new OutboxMessage)->getDateFormat());
        $deleted = 0;

        // Small id-based chunks keep each DELETE short instead of locking a large range at once.
        do {
            $ids = OutboxMessage::query()
                ->where('status', OutboxStatus::Published->value)
                ->where('published_at', '<', $before)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            $deleted += $ids === [] ? 0 : OutboxMessage::query()->whereKey($ids)->delete();
        } while (count($ids) === $chunk);

        $this->components->info("{$deleted} published message(s) pruned.");

        return self::SUCCESS;
    }
}
