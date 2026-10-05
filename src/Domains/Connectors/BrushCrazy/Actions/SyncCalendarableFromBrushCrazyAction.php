<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CalendarableMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\EventClassMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\EventStatusMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Themes\Models\Theme;
use RuntimeException;
use stdClass;

/**
 * Writes one BrushCrazy calendarable into Kanvas as an Event + EventVersion (+ date).
 *
 * This is the single write path: the bulk import loops over source rows calling it, and the mirror
 * webhook calls it for one row at a time. Two implementations would drift, and the mirror would
 * quietly start producing different rows than the import did.
 *
 * Idempotent by construction — every key derives from the source id, so a re-run updates rather
 * than duplicates.
 */
class SyncCalendarableFromBrushCrazyAction
{
    protected IdMapRepository $idMaps;

    /** @var array<string, int> */
    protected array $lookups = [];

    /** @var array<string, int>|null */
    protected ?array $themes = null;

    protected ?int $currencyId = null;

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected Carbon $correctionCutoff,
        ?IdMapRepository $idMaps = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
    }

    public function fromRow(CalendarableTypeEnum $morph, stdClass $row): EventVersion
    {
        $mapped = CalendarableMapper::map(
            $morph,
            $row,
            $this->context->bcStudioId,
            $this->context->timezone,
            $this->correctionCutoff,
        );

        $status = EventStatusMapper::resolve($morph, $row);
        $class = EventClassMapper::resolve($row);

        $event = $this->upsertEvent(
            $morph,
            $row,
            $mapped,
            $status->value,
            $class->value
        );
        $version = $this->upsertVersion(
            $morph,
            $event,
            $mapped,
            $status->value
        );

        $this->upsertDate($version, $mapped);

        return $version;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertEvent(
        CalendarableTypeEnum $morph,
        stdClass $row,
        array $mapped,
        string $statusSlug,
        string $classValue,
    ): Event {
        $attributes = $mapped['event'] + [
            'users_id' => $this->user->getId(),
            'theme_id' => $this->resolveThemeId($row),
            'theme_area_id' => $this->context->themeAreaId(),
            'event_status_id' => $this->lookup('status:' . $statusSlug),
            'event_type_id' => $this->lookup('type:' . $morph->value),
            'event_class_id' => $this->lookup('class:' . $classValue),
            'event_category_id' => $this->resolveCategoryId($morph, $row, $classValue),
        ];

        $event = $this->upsertQuietly(Event::class, ['slug' => $attributes['slug']], $attributes);

        $event->set(CustomFieldEnum::BRUSHCRAZY_EVENT_GROUP_KEY->value, $mapped['group_key']);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertVersion(
        CalendarableTypeEnum $morph,
        Event $event,
        array $mapped,
        string $statusSlug,
    ): EventVersion {
        $attributes = $mapped['version'] + [
            'users_id' => $this->user->getId(),
            'event_id' => $event->getId(),
            'event_status_id' => $this->lookup('status:' . $statusSlug),
            'currency_id' => $this->currencyId(),
            'price_per_ticket' => 0,
        ];

        $version = $this->upsertQuietly(
            EventVersion::class,
            ['event_id' => $event->getId(), 'version' => $attributes['version']],
            $attributes,
        );

        $version->set(
            CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY->value,
            $morph->value . ':' . $mapped['version']['version'],
        );

        return $version;
    }

    /**
     * Observers stay off for the whole write. EventVersionObserver creates a social Channel per
     * version and re-aggregates the parent Event, and the participant observer bumps
     * total_attendees on any save — side effects that turn a bulk import into hours of work and
     * that corrupt capacity when the mirror re-saves an unchanged row.
     *
     * The trade-off is that UuidTrait and SlugTrait no longer fire, so uuid has to be set by hand
     * here. That is also what lets the source's own created_at be preserved.
     *
     * withTrashed(): a source soft delete is written as is_deleted = 1, and the global scope would
     * hide that row from the next sync, which would then create a duplicate instead of restoring it.
     *
     * Explicit apps_id/companies_id rather than fromApp()/fromCompany(): under an AppKey request
     * fromCompany() widens to every company.
     *
     * @template T of Model
     * @param  class-string<T>  $model
     * @param  array<string, mixed>  $match
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    protected function upsertQuietly(string $model, array $match, array $attributes): Model
    {
        $tenant = [
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->context->companyId(),
        ];

        return $model::withoutEvents(function () use ($model, $match, $attributes, $tenant) {
            $existing = $model::withTrashed()->where($match + $tenant)->first();

            if ($existing !== null) {
                $existing->fill($attributes)->saveQuietly();

                return $existing;
            }

            return $model::create($attributes + ['uuid' => Str::uuid()->toString()] + $tenant);
        });
    }

    /**
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertDate(EventVersion $version, array $mapped): void
    {
        if ($mapped['date'] === null) {
            return;
        }

        EventVersionDate::withoutEvents(function () use ($version, $mapped) {
            EventVersionDate::firstOrCreate(
                [
                    'event_version_id' => $version->getId(),
                    'event_date' => $mapped['date']['event_date'],
                    'start_time' => $mapped['date']['start_time'],
                    'end_time' => $mapped['date']['end_time'],
                ],
                ['users_id' => $this->user->getId()],
            );
        });
    }

    /**
     * 78% of production calendarables carry no painting, so Unassigned is the normal outcome here
     * rather than an error path.
     */
    protected function resolveThemeId(stdClass $row): int
    {
        $paintingId = $row->painting_id ?? null;

        if ($paintingId !== null) {
            $map = $this->themeMap();

            if (isset($map[(string) $paintingId])) {
                return $map[(string) $paintingId];
            }
        }

        return $this->lookup('theme:unassigned');
    }

    /**
     * Leaves are created lazily from the source's free-text `type`. Production has only 7 distinct
     * (morph, type) pairs, so this converges after a handful of rows instead of growing a long tail.
     */
    protected function resolveCategoryId(CalendarableTypeEnum $morph, stdClass $row, string $classValue): int
    {
        $type = trim((string) ($row->type ?? ''));

        if ($type === '') {
            return $this->lookup('category:' . $morph->value);
        }

        $category = EventCategory::firstOrCreate(
            [
                'slug' => 'bc-cat-' . $morph->value . '-' . Str::slug($type),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->context->companyId(),
            ],
            [
                'users_id' => $this->user->getId(),
                'name' => $type,
                'parent_id' => $this->lookup('category:' . $morph->value),
                'event_type_id' => $this->lookup('type:' . $morph->value),
                'event_class_id' => $this->lookup('class:' . $classValue),
                'position' => 0,
            ],
        );

        return (int) $category->getId();
    }

    protected function lookup(string $key): int
    {
        if ($this->lookups === []) {
            $companyId = $this->context->companyId();

            foreach ([EventType::class, EventClass::class, EventStatus::class, EventCategory::class, Theme::class] as $model) {
                $this->lookups += $this->idMaps->load($companyId, $model, CustomFieldEnum::BRUSHCRAZY_LOOKUP_KEY);
            }
        }

        return $this->lookups[$key]
            ?? throw new RuntimeException("BrushCrazy lookup '{$key}' is missing; run the lookup seeding first.");
    }

    /**
     * @return array<string, int>
     */
    protected function themeMap(): array
    {
        return $this->themes ??= $this->idMaps->load(
            $this->context->companyId(),
            Theme::class,
            CustomFieldEnum::BRUSHCRAZY_PAINTING_ID,
        );
    }

    protected function currencyId(): int
    {
        return $this->currencyId ??= (int) Currencies::where('code', 'USD')->firstOrFail()->getId();
    }
}
