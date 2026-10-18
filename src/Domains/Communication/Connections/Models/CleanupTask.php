<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Models\BaseModel;

/**
 * One vendor resource a disconnect still has to remove. Persisted in the same transaction that
 * marks the connection disconnecting, so a worker dying halfway leaves the work on record.
 *
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $connection_id
 * @property string $resource_type
 * @property string $resource_key
 * @property string|null $external_id
 * @property string|null $expected_value what we set; anything else at the vendor is not ours to remove
 * @property CleanupTaskStatusEnum $status
 * @property int $attempts
 */
class CleanupTask extends BaseModel
{
    protected $table = 'communication_cleanup_tasks';

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => CleanupTaskStatusEnum::class,
            'next_attempt_at' => 'datetime',
            'lease_until' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }
}
