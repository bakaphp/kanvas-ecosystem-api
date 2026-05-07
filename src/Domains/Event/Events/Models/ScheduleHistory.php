<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Users\Models\Users;

/**
 * @property int $id
 * @property int $apps_id
 * @property int $companies_id
 * @property int $users_id
 * @property int $resources_id
 * @property string $resources_type
 * @property string $action
 * @property array|null $payload
 * @property bool $is_deleted
 */
class ScheduleHistory extends BaseModel
{
    protected $table = 'schedule_history';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'is_deleted' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Users::class, 'users_id');
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }
}
