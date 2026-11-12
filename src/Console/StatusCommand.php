<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;

#[AsCommand(name: 'outbox:status')]
class StatusCommand extends Command
{
    protected $signature = 'outbox:status {--json : Output as JSON (for monitoring)}';

    protected $description = 'Show outbox counters and the publishing lag';

    public function handle(): int
    {
        $status = $this->collect();

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=green;options=bold>Outbox</>');
        $this->components->twoColumnDetail('Pending', (string) $status['pending']);
        $this->components->twoColumnDetail('  due now', (string) $status['due']);
        $this->components->twoColumnDetail('  leased by a relay', (string) $status['leased']);
        $this->components->twoColumnDetail('  waiting for retry', (string) $status['retrying']);
        $this->components->twoColumnDetail('Dead', $status['dead'] > 0 ? "<fg=red>{$status['dead']}</>" : '0');
        $this->components->twoColumnDetail('Published', (string) $status['published']);
        $this->components->twoColumnDetail('Oldest pending', $status['lag_seconds'] === null ? '-' : $status['lag_seconds'].'s ago');

        return self::SUCCESS;
    }

    /**
     * @return array{pending: int, due: int, leased: int, retrying: int, dead: int, published: int, lag_seconds: ?int}
     */
    protected function collect(): array
    {
        $now = Date::now();
        $format = (new OutboxMessage)->getDateFormat();
        $pending = fn () => OutboxMessage::query()->where('status', OutboxStatus::Pending->value);

        $counts = OutboxMessage::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $oldest = $pending()->min('created_at');

        return [
            'pending' => (int) ($counts[OutboxStatus::Pending->value] ?? 0),
            'due' => $pending()->where('available_at', '<=', $now->format($format))
                ->where(fn ($q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', $now->format($format)))
                ->count(),
            'leased' => $pending()->where('locked_until', '>', $now->format($format))->count(),
            'retrying' => $pending()->where('attempts', '>', 0)->count(),
            'dead' => (int) ($counts[OutboxStatus::Dead->value] ?? 0),
            'published' => (int) ($counts[OutboxStatus::Published->value] ?? 0),
            'lag_seconds' => $oldest === null ? null : (int) max(0, Date::parse($oldest)->diffInSeconds($now, true)),
        ];
    }
}
