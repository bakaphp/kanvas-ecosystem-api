<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\SlugTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Nevadskiy\Tree\AsTree;

class EventCategory extends BaseModel
{
    use SlugTrait;
    use AsTree;
    
    protected $table = 'event_categories';
    protected $guarded = [];

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }

    public function eventClass(): BelongsTo
    {
        return $this->belongsTo(EventClass::class);
    }
}
