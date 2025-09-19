<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;

class ScheduleException extends BaseModel
{
    protected $table = 'schedule_exceptions';
    protected $guarded = [];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'resources_id',
        'resources_type',
        'window_start',
        'window_end',
        'kind',
        'reason',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }
}
