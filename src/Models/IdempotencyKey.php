<?php

namespace Toshkq93\Outbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A row of the "database" idempotency driver.
 *
 * @property int $id
 * @property string $scope
 * @property string $key
 * @property string $fingerprint
 * @property string $status in_progress | completed
 * @property string|null $owner
 * @property Carbon|null $locked_until
 * @property int|null $response_status
 * @property string|null $response_headers JSON
 * @property string|null $response_body base64
 * @property Carbon $created_at
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $casts = [
        'response_status' => 'integer',
        'locked_until' => 'datetime',
        'created_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return config('outbox.connection') ?? parent::getConnectionName();
    }

    public function getTable()
    {
        return config('outbox.idempotency.table', 'idempotency_keys');
    }
}
