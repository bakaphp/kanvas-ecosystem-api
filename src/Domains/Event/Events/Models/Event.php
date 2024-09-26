<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Workflow\Traits\CanUseWorkflow;

class Event extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;

    protected $table = 'events';
    protected $guarded = [];

    protected $is_deleted;

    public function versions(): HasMany
    {
        return $this->hasMany(EventVersion::class);
    }
}
