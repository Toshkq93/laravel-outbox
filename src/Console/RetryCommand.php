<?php

namespace Toshkq93\Outbox\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Symfony\Component\Console\Attribute\AsCommand;
use Toshkq93\Outbox\Models\OutboxMessage;
use Toshkq93\Outbox\OutboxStatus;

#[AsCommand(name: 'outbox:retry')]
class RetryCommand extends Command
{
    protected $signature = 'outbox:retry
        {id?* : Message ids (uuid) or row ids of dead messages}
        {--type= : Retry dead messages of this type}
        {--all : Retry all dead messages}';

    protected $description = 'Put dead outbox messages back in the queue for publishing';

    public function handle(): int
    {
        $ids = (array) $this->argument('id');
        $type = $this->option('type');

        if ($ids === [] && $type === null && ! $this->option('all')) {
            $this->components->error('Pass message ids, --type or --all.');

            return self::FAILURE;
        }

        // Row ids are numeric, message ids are uuids; keep them apart (uuid columns reject "42").
        $rowIds = array_values(array_filter($ids, 'ctype_digit'));
        $messageIds = array_values(array_diff($ids, $rowIds));

        $query = OutboxMessage::query()->where('status', OutboxStatus::Dead->value)
            ->when($ids !== [], fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->when($messageIds !== [], fn (Builder $query) => $query->orWhereIn('message_id', $messageIds))
                ->when($rowIds !== [], fn (Builder $query) => $query->orWhereIn('id', $rowIds))))
            ->when($type !== null, fn (Builder $query) => $query->where('type', $type));

        $count = $query->update([
            'status' => OutboxStatus::Pending->value,
            'attempts' => 0,
            'available_at' => Date::now()->format((new OutboxMessage)->getDateFormat()),
            'locked_until' => null,
            'locked_by' => null,
        ]);

        $this->components->info("{$count} dead message(s) queued for publishing again.");

        return self::SUCCESS;
    }
}
