<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;

class TimeSlots extends BaseModel
{
    protected $table = 'time_slots';
    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'capacity' => 'integer',
        'price_snapshot_cents' => 'integer',
        'meta' => 'array',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'resources_id',
        'resources_type',
        'start_at',
        'end_at',
        'capacity',
        'status',
        'price_snapshot',
        'currency',
        'meta',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }
}