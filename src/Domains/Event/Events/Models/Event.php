<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Observers\ClearsLightHouseCacheObserver;
use Baka\Traits\DynamicSearchableTrait;
use Baka\Traits\HasLightHouseCache;
use Baka\Traits\SlugTrait;
use Baka\Traits\UuidTrait;
use Baka\Users\Contracts\UserInterface;
use Dyrynda\Database\Support\CascadeSoftDeletes;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Contracts\ReadableAtLocation;
use Kanvas\Companies\Support\LocationContext;
use Kanvas\Event\Events\Enums\EventStatusEnum;
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

#[ObservedBy([ClearsLightHouseCacheObserver::class])]
class Event extends BaseModel implements ReadableAtLocation
{
    use UuidTrait;
    use SlugTrait;
    use CanUseWorkflow;
    use CascadeSoftDeletes;
    use FollowersTrait;
    use HasTagsTrait;
    use HasLightHouseCache;
    use DynamicSearchableTrait {
        search as public traitSearch;
    }

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

    public function isCancelled(): bool
    {
        return $this->eventStatus?->name === EventStatusEnum::CANCELLED->value;
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

    public function latestVersion(): ?EventVersion
    {
        return $this->versions()->latest('id')->first();
    }

    /**
     * The start the latest version records, in UTC: `start_at` when set, else the first date row.
     * Null for an event with no dates at all.
     */
    public function startsAt(): ?Carbon
    {
        $version = $this->latestVersion();

        if ($version?->start_at !== null) {
            return $version->start_at->copy()->utc();
        }

        $date = $version?->dates()->first();

        if ($date === null) {
            return null;
        }

        return Carbon::parse(
            $date->event_date->format('Y-m-d') . ' ' . ((string) ($date->start_time ?? '00:00'))
        )->utc();
    }

    /**
     * The entity this event is about, by class: the primary `resource` morph when it is one, else the
     * first matching `event_resources` row. Bookings made for a lead record it in both places.
     */
    public function linkedResource(string $class): ?Model
    {
        if ($this->resources_type === $class && $this->resources_id) {
            return $this->resource;
        }

        return $this->resources()->where('resources_type', $class)->first()?->resource;
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

    public function searchableAs(): string
    {
        $app = $this->app ?? app(Apps::class);
        $customIndex = $app->get('app_custom_event_index') ?? null;

        return config('scout.prefix') . ($customIndex ?? 'event_index');
    }

    public function toSearchableArray(): array
    {
        return [
            'objectID' => (string) $this->id,
            'id' => (string) $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'files' => $this->getFiles()->take(5)->map(fn ($file) => [
                'uuid' => $file->uuid,
                'name' => $file->name,
                'url' => $file->url,
                'size' => $file->size,
                'field_name' => $file->field_name,
            ]),
            // `config` from EventInput has no first-class column on `events` — it's persisted as
            'config' => $this->versions()->latest('version')->first()?->metadata,
            'apps_id' => $this->apps_id,
            'companies_id' => $this->companies_id,
        ];
    }

    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                ['name' => 'objectID', 'type' => 'string'],
                ['name' => 'id', 'type' => 'string'],
                ['name' => 'uuid', 'type' => 'string'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'slug', 'type' => 'string', 'optional' => true],
                ['name' => 'description', 'type' => 'string', 'optional' => true],
                ['name' => 'files', 'type' => 'object[]', 'optional' => true],
                ['name' => 'config', 'type' => 'object', 'optional' => true],
                ['name' => 'apps_id', 'type' => 'int64'],
                ['name' => 'companies_id', 'type' => 'int64', 'facet' => true],
            ],
            'enable_nested_fields' => true,
        ];
    }

    #[Override]
    public function shouldBeSearchable(): bool
    {
        return ! $this->isDeleted();
    }

    public static function search($query = '', $callback = null)
    {
        $query = self::traitSearch($query, $callback)->where('apps_id', app(Apps::class)->getId());
        $user = auth()->user();

        $locationCompany = $user instanceof UserInterface ? LocationContext::readableCompany(static::class, $user) : null;

        if ($locationCompany !== null) {
            $query->where('companies_id', $locationCompany->getId());
        } elseif ($user instanceof UserInterface && ! $user->isAppOwner()) {
            $query->where('companies_id', $user->getCurrentCompany()->getId());
        }

        if ($query->model->isTypesense()) {
            $query->options(['query_by' => 'name,slug,description']);
        }

        return $query;
    }
}
