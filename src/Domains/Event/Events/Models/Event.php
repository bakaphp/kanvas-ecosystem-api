<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Workflow\Traits\CanUseWorkflow;

class Event extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;
    use CascadeSoftDeletes;

    protected $cascadeDeletes = ['versions'];

    protected $table = 'events';
    protected $guarded = [];

    protected $is_deleted;

    public function versions(): HasMany
    {
        return $this->hasMany(EventVersion::class);
    }

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }

    public function themeArea(): BelongsTo
    {
        return $this->belongsTo(ThemeArea::class);
    }

    public function eventStatus(): BelongsTo
    {
        return $this->belongsTo(EventStatus::class);
    }

    public function eventCategory(): BelongsTo
    {
        return $this->belongsTo(EventCategory::class);
    }

    public function eventClass(): BelongsTo
    {
        return $this->belongsTo(EventClass::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(EventType::class);
    }
}
