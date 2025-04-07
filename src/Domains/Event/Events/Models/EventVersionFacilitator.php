<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Facilitators\Models\Facilitator;
use Kanvas\Event\Models\BaseModel;

class EventVersionFacilitator extends BaseModel
{
    protected $table = 'event_version_facilitators';
    protected $guarded = [];

    protected $is_deleted;

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }

    public function facilitator(): BelongsTo
    {
        return $this->belongsTo(Facilitator::class);
    }
}
