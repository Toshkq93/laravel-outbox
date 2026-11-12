<?php

namespace Toshkq93\Outbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A message a consumer has handled (see Inbox::once()).
 *
 * @property int $id
 * @property string $message_id
 * @property string $consumer
 * @property string|null $type
 * @property Carbon $processed_at
 */
class InboxMessage extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return config('outbox.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('outbox.inbox.table', 'inbox_messages');
    }
}
