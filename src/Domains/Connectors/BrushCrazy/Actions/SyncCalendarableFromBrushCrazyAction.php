<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\BrushCrazy\Client;
use Kanvas\Connectors\BrushCrazy\Concerns\SyncsAttachedPhotos;
use Kanvas\Connectors\BrushCrazy\Concerns\UpsertsMirroredRows;
use Kanvas\Connectors\BrushCrazy\Enums\CalendarableTypeEnum;
use Kanvas\Connectors\BrushCrazy\Enums\CustomFieldEnum;
use Kanvas\Connectors\BrushCrazy\Mappers\CalendarableMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\EventClassMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\EventStatusMapper;
use Kanvas\Connectors\BrushCrazy\Mappers\PaintingMapper;
use Kanvas\Connectors\BrushCrazy\Support\IdMapRepository;
use Kanvas\Connectors\BrushCrazy\Support\PaintingRelations;
use Kanvas\Connectors\BrushCrazy\Support\StudioContext;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Facilitators\Models\EventVersionFacilitator;
use Kanvas\Event\Facilitators\Models\Facilitator;
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
    use SyncsAttachedPhotos;
    use UpsertsMirroredRows;

    protected IdMapRepository $idMaps;

    /** @var array<string, int> */
    protected array $lookups = [];

    /** @var array<string, int>|null */
    protected ?array $themes = null;

    protected ?int $currencyId = null;

    /** @var array<string, int>|null BrushCrazy user id => facilitators.id */
    protected ?array $facilitators = null;

    protected ?string $mediaBaseUrl;

    /** @var array<string, array{media_id: int, url: string}> "{morph}:{id}" => photo */
    protected array $photos = [];

    /** @var array<string, true> calendarables whose photo lookup already ran, photo or not */
    protected array $photosLoaded = [];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected StudioContext $context,
        protected Carbon $correctionCutoff,
        ?IdMapRepository $idMaps = null,
        protected ?Client $client = null,
    ) {
        $this->idMaps = $idMaps ?? new IdMapRepository();
        $this->mediaBaseUrl = self::mediaBaseUrlOf($app);
    }

    /**
     * One media query per chunk instead of one per class. A class missing from the preload (the
     * webhook path) is looked up on its own.
     *
     * @param  array<int, int>  $bcIds
     */
    public function preloadPhotos(CalendarableTypeEnum $morph, array $bcIds): void
    {
        if ($this->mediaBaseUrl === null || $bcIds === []) {
            return;
        }

        $media = PaintingRelations::mediaQuery($this->client(), [$morph->value => $bcIds])->get()->all();

        $this->photos = PaintingMapper::photos($media, $this->mediaBaseUrl);
        $this->photosLoaded = array_fill_keys(
            array_map(fn (int $id) => $morph->key($id), $bcIds),
            true
        );
    }

    /**
     * Loads each row's live ticket options onto `$row->substrates`, one query for the batch. A row
     * without that property keeps the snapshot its version already has, so a caller that does not
     * read the source table never wipes it.
     *
     * Editing an option does not touch the class's `updated_at`, so a `--since` pull misses it until
     * the class itself changes or a full pull runs.
     *
     * @param  array<int, stdClass>  $rows
     */
    public static function attachSubstrates(Client $client, CalendarableTypeEnum $morph, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $byOwner = $client->table('calendarable_substrates')
            ->where('calendarable_type', $morph->value)
            ->whereIn('calendarable_id', array_map(fn (stdClass $row) => (int) $row->id, $rows))
            ->whereNull('deleted_at')
            ->get()
            ->groupBy(fn (stdClass $substrate) => (int) $substrate->calendarable_id);

        foreach ($rows as $row) {
            $row->substrates = $byOwner->get((int) $row->id)?->all() ?? [];
        }
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
        $substrates = property_exists($row, 'substrates') ? CalendarableMapper::substrates($row->substrates) : null;

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
            $status->value,
            $substrates['price_per_ticket'] ?? null
        );

        $this->upsertDate($version, $mapped);
        $this->linkInstructor($version, $row);

        if ($substrates !== null) {
            $this->writeMirroredFields($version, [
                CustomFieldEnum::BRUSHCRAZY_SUBSTRATES->value => $substrates['options'] !== [] ? $substrates['options'] : null,
            ]);
        }

        // Without a base URL there is nothing to point at; reading that as "no photo" would detach
        // the photo a previous run attached.
        if ($this->mediaBaseUrl !== null) {
            $this->syncPhotos($version, $this->photoFor($morph, (int) $row->id));
        }

        return $version;
    }

    /**
     * Photo edits emit no pointer at the source (media is not in the morph map), so a new photo
     * arrives with the class's next change or the next full pull.
     *
     * @return array<string, array{media_id: int, url: string}>
     */
    protected function photoFor(CalendarableTypeEnum $morph, int $bcId): array
    {
        $key = $morph->key($bcId);

        if (! isset($this->photosLoaded[$key])) {
            $this->preloadPhotos($morph, [$bcId]);
        }

        return isset($this->photos[$key]) ? [PaintingMapper::PHOTO_FIELD => $this->photos[$key]] : [];
    }

    protected function client(): Client
    {
        return $this->client ??= new Client($this->app);
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

        $event = $this->upsertMirrored(
            Event::class,
            ['slug' => $attributes['slug']],
            $attributes,
            ['uuid' => Str::uuid()->toString()]
        );

        $this->writeMirroredFields($event, [CustomFieldEnum::BRUSHCRAZY_EVENT_GROUP_KEY->value => $mapped['group_key']]);

        return $event;
    }

    /**
     * A null price means the row carried no ticket options to price from, so an existing version
     * keeps its price the same way it keeps its options snapshot.
     *
     * @param  array<string, mixed>  $mapped
     */
    protected function upsertVersion(
        CalendarableTypeEnum $morph,
        Event $event,
        array $mapped,
        string $statusSlug,
        ?float $pricePerTicket,
    ): EventVersion {
        $attributes = $mapped['version'] + [
            'users_id' => $this->user->getId(),
            'event_id' => $event->getId(),
            'event_status_id' => $this->lookup('status:' . $statusSlug),
            'currency_id' => $this->currencyId(),
        ];
        $insertOnly = ['uuid' => Str::uuid()->toString(), 'price_per_ticket' => 0.0];

        if ($pricePerTicket !== null) {
            $attributes['price_per_ticket'] = $pricePerTicket;
        }

        $version = $this->upsertMirrored(
            EventVersion::class,
            ['event_id' => $event->getId(), 'version' => $attributes['version']],
            $attributes,
            $insertOnly
        );

        $this->writeMirroredFields($version, [
            CustomFieldEnum::BRUSHCRAZY_CALENDARABLE_KEY->value => $morph->key($mapped['version']['version']),
        ]);

        return $version;
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
     * `assignee_id` is the instructor; shifts are only scheduling. A reassignment or an unassign
     * soft-deletes the previous link instead of removing it, and a reassignment back restores it.
     *
     * Only links to BrushCrazy Facilitators are touched — one a user added in Kanvas points at a
     * Facilitator with no BRUSHCRAZY_USER_ID and is not the mirror's to remove.
     */
    protected function linkInstructor(EventVersion $version, stdClass $row): void
    {
        $assigneeId = isset($row->assignee_id) ? (int) $row->assignee_id : null;
        $facilitatorId = $assigneeId !== null ? $this->facilitatorId($assigneeId) : null;

        EventVersionFacilitator::withoutEvents(function () use ($version, $facilitatorId) {
            // A version created in this call has no links yet; skipping the read saves a query per
            // row on the first full import.
            $links = $version->wasRecentlyCreated
                ? new EloquentCollection()
                : EventVersionFacilitator::withTrashed()
                    ->where('event_version_id', $version->getId())
                    ->get();

            $mirrored = $this->idMaps->loadReverse(
                $this->context->companyId(),
                Facilitator::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID,
                $links->pluck('facilitator_id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            );

            foreach ($links as $link) {
                $isCurrent = (int) $link->facilitator_id === $facilitatorId;

                if (! $isCurrent && ! isset($mirrored[(int) $link->facilitator_id])) {
                    continue;
                }

                $link->is_deleted = ! $isCurrent;

                if ($link->isDirty()) {
                    $link->saveQuietly();
                }
            }

            if ($facilitatorId !== null && ! $links->contains(fn ($link) => (int) $link->facilitator_id === $facilitatorId)) {
                EventVersionFacilitator::create([
                    'event_version_id' => $version->getId(),
                    'facilitator_id' => $facilitatorId,
                    'is_deleted' => 0,
                ]);
            }
        });
    }

    /**
     * A studio has tens of staff, so the whole map is loaded once rather than per chunk. A miss
     * is re-checked once: under the webhook a staff pointer earlier in the same batch may have
     * just created the Facilitator.
     */
    protected function facilitatorId(int $bcUserId): ?int
    {
        $this->facilitators ??= $this->idMaps->load(
            $this->context->companyId(),
            Facilitator::class,
            CustomFieldEnum::BRUSHCRAZY_USER_ID,
        );

        if (! isset($this->facilitators[(string) $bcUserId])) {
            $found = $this->idMaps->loadFor(
                $this->context->companyId(),
                Facilitator::class,
                CustomFieldEnum::BRUSHCRAZY_USER_ID,
                [$bcUserId],
            );

            $this->facilitators += $found;
        }

        return $this->facilitators[(string) $bcUserId] ?? null;
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
