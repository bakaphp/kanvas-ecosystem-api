<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;

class ScheduleRules extends BaseModel
{
    use UuidTrait;

    protected $table = 'schedule_rules';
    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_deleted' => 'boolean',
        'slot_duration_min' => 'integer',
        'lead_time_min' => 'integer',
        'cutoff_time_min' => 'integer',
        'capacity_override' => 'integer',
        'metadata' => 'array',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'uuid',
        'resources_id',
        'resources_type',
        'start_at',
        'end_at',
        'rrule',
        'day_rrule',
        'slot_duration_min',
        'lead_time_min',
        'cutoff_time_min',
        'capacity_override',
        'metadata',
        'is_deleted',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }
}
