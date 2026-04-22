<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Events\Observers\EventObserver;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Social\Channels\Enums\ChannelNameEnum;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Follows\Traits\FollowersTrait;
use Kanvas\Social\Tags\Traits\HasTagsTrait;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

#[ObservedBy([EventObserver::class])]
class Event extends BaseModel
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;
    use CascadeSoftDeletes;
    use FollowersTrait;
    use HasTagsTrait;
    use HasLightHouseCache;

    protected $cascadeDeletes = ['versions'];

    protected $table = 'events';
    protected $guarded = [];

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

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }

    public function orders(): MorphMany
    {
        return $this->morphMany(Order::class, 'resources');
    }

    public function resources(): HasMany
    {
        return $this->hasMany(EventResource::class);
    }

    public function getStringIdAttribute(): string
    {
        return (string) $this->id;
    }

    public function socialChannels(): HasMany
    {
        return $this->hasMany(Channel::class, 'entity_id', 'string_id')
            ->whereIn(
                'entity_namespace',
                [
                    self::class,
                    SystemModules::getLegacyNamespace(self::class),
                ],
            )
            ->where('is_deleted', 0);
    }

    public function notes(): HasOne
    {
        return $this->hasOne(Channel::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class)
            ->where('name', ChannelNameEnum::NOTES->value);
    }

    public function aiSession(): HasMany
    {
        return $this->hasMany(Session::class, 'entity_id', 'string_id')
            ->where('entity_namespace', self::class);
    }

    #[Override]
    public function getGraphTypeName(): string
    {
        return 'Event';
    }
}
