<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

class EventVersion extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;

    protected $table = 'event_versions';
    protected $guarded = [];

    protected $is_deleted;

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
