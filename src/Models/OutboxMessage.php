<?php

namespace Toshkq93\Outbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Toshkq93\Outbox\Message;
use Toshkq93\Outbox\OutboxStatus;

/**
 * @property int $id
 * @property string $message_id
 * @property string $type
 * @property array<array-key, mixed> $payload
 * @property array<string, mixed>|null $headers
 * @property string|null $aggregate_type
 * @property string|null $aggregate_id
 * @property string|null $publisher
 * @property OutboxStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $available_at
 * @property Carbon|null $locked_until
 * @property string|null $locked_by
 * @property Carbon|null $published_at
 * @property Carbon $created_at
 */
class OutboxMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'status' => OutboxStatus::class,
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'locked_until' => 'datetime',
        'published_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return config('outbox.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('outbox.outbox.table', 'outbox_messages');
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[$name] ?? $default;
    }

    public function correlationId(): ?string
    {
        return $this->header('correlation_id');
    }

    public function causationId(): ?string
    {
        return $this->header('causation_id');
    }

    public function toMessage(): Message
    {
        return new Message(
            messageId: $this->message_id,
            type: $this->type,
            payload: $this->payload,
            headers: $this->headers ?? [],
            aggregateType: $this->aggregate_type,
            aggregateId: $this->aggregate_id,
            createdAt: $this->created_at->format('Y-m-d\TH:i:s.uP'),
        );
    }
}
