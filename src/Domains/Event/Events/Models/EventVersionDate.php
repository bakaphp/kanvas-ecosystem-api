<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;

class EventVersionDate extends BaseModel
{
    protected $table = 'event_version_dates';
    protected $guarded = [];

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }
}
