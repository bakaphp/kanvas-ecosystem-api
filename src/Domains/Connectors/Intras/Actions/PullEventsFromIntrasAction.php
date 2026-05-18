<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionDate;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class PullEventsFromIntrasAction
{
    /** @var array<string, array<int|string, int>> [modelClass => [intras_id => kanvas_id]] */
    protected array $idMaps = [];

    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?string $lastSyncAt = null,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): array
    {
        $client = new Client($this->app);
        $counts = ['events' => 0, 'versions' => 0, 'dates' => 0];

        // Preload [intras_id => kanvas_id] for every classification table the loops
        // need. Replaces ~6 whereHas subqueries per event row + 1 per version + 1
        // per date with a fixed handful of queries upfront.
        $this->preloadMaps();

        $this->pullEvents($client, $counts);
        $this->pullEventVersions($client, $counts);
        $this->pullEventVersionDates($client, $counts);

        return $counts;
    }

    protected function pullEvents(Client $client, array &$counts): void
    {
        $query = $client->table('events')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $defaultType = EventType::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();
        $defaultClass = EventClass::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();
        $defaultCategory = EventCategory::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();
        $defaultStatus = EventStatus::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();
        $defaultTheme = Theme::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();
        $defaultThemeArea = ThemeArea::where('apps_id', $this->app->getId())->where('companies_id', $this->company->getId())->first();

        $query->orderBy('id')->chunk(500, function ($rows) use (&$counts, $defaultType, $defaultClass, $defaultCategory, $defaultStatus, $defaultTheme, $defaultThemeArea) {
            foreach ($rows as $row) {
                $eventTypeId = $this->mapId(EventType::class, $row->events_types_id) ?? $defaultType?->getId();
                $eventClassId = $this->mapId(EventClass::class, $row->events_classes_id) ?? $defaultClass?->getId();
                $eventCategoryId = $this->mapId(EventCategory::class, $row->events_categories_id) ?? $defaultCategory?->getId();
                $eventStatusId = $this->mapId(EventStatus::class, $row->events_statuses_id) ?? $defaultStatus?->getId();
                $themeId = $this->mapId(Theme::class, $row->themes_id) ?? $defaultTheme?->getId();
                $themeAreaId = $this->mapId(ThemeArea::class, $row->themes_areas_id) ?? $defaultThemeArea?->getId();

                $slug = Str::slug(trim($row->name) . '-' . $row->id);

                $event = Event::firstOrCreate([
                    'slug' => $slug,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'name' => trim($row->name),
                    'event_type_id' => $eventTypeId,
                    'event_class_id' => $eventClassId,
                    'event_category_id' => $eventCategoryId,
                    'event_status_id' => $eventStatusId,
                    'theme_id' => $themeId,
                    'theme_area_id' => $themeAreaId,
                ]);

                $event->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
                $event->set(CustomFieldEnum::INTRAS_AGENCY_ID->value, $row->agencies_id);

                // Keep the in-memory event map current so subsequent versions/dates
                // in the same execute() resolve without a re-query.
                $this->idMaps[Event::class][$row->id] = (int) $event->getId();

                $counts['events']++;
            }
        });
    }

    protected function pullEventVersions(Client $client, array &$counts): void
    {
        $query = $client->table('events_versions')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        if ($this->lastSyncAt !== null) {
            $query->where('updated_at', '>=', $this->lastSyncAt);
        }

        $defaultCurrency = Currencies::where('code', 'USD')->first();

        $query->orderBy('id')->chunk(500, function ($rows) use (&$counts, $defaultCurrency) {
            foreach ($rows as $row) {
                $kanvasEventId = $this->mapId(Event::class, $row->events_id);
                if ($kanvasEventId === null) {
                    continue;
                }

                $slug = Str::slug(trim($row->name) . '-v' . $row->version . '-' . $row->id);

                $eventVersion = EventVersion::firstOrCreate([
                    'slug' => $slug,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'event_id' => $kanvasEventId,
                    'users_id' => $this->user->getId(),
                    'name' => trim($row->name),
                    'version_number' => $row->version ?? 1,
                    'version' => (string) ($row->version ?? '1'),
                    'classification' => $row->classification ?? null,
                    'price_per_ticket' => $row->price_per_ticket ?? 0,
                    'total_attendees' => 0,
                    'currency_id' => $defaultCurrency?->getId(),
                    'metadata' => [
                        'max_capacity' => $row->max_capacity ?? 0,
                        'has_book' => (bool) ($row->has_book ?? false),
                        'has_forum' => (bool) ($row->has_forum ?? false),
                        'has_graduation' => (bool) ($row->has_graduation ?? false),
                        'has_translations' => (bool) ($row->has_translations ?? false),
                    ],
                ]);

                $eventVersion->set(CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value, $row->id);

                // Keep the version map current so pullEventVersionDates sees this row.
                $this->idMaps[EventVersion::class][$row->id] = (int) $eventVersion->getId();

                $counts['versions']++;
            }
        });
    }

    protected function pullEventVersionDates(Client $client, array &$counts): void
    {
        // events_versions_dates has no agencies_id — join to events_versions to
        // filter by agency at the source instead of pulling the full table.
        $query = $client->table('events_versions_dates as evd')
            ->where('evd.is_deleted', 0)
            ->select('evd.*');

        if ($this->agencyId !== null) {
            $query->join('events_versions as ev', 'ev.id', '=', 'evd.events_versions_id')
                ->where('ev.agencies_id', $this->agencyId);
        }

        $query->orderBy('evd.id')->chunk(500, function ($rows) use (&$counts) {
            foreach ($rows as $row) {
                $kanvasVersionId = $this->mapId(EventVersion::class, $row->events_versions_id);
                if ($kanvasVersionId === null) {
                    continue;
                }

                EventVersionDate::firstOrCreate([
                    'event_version_id' => $kanvasVersionId,
                    'event_date' => $row->event_date,
                    'start_time' => $row->start_time,
                    'end_time' => $row->end_time,
                ], [
                    'users_id' => $this->user->getId(),
                ]);

                $counts['dates']++;
            }
        });
    }

    /**
     * Preload [intras_id => kanvas_id] maps for every model the loops resolve.
     * 8 queries upfront, all index-backed via `idx_company_model_name_value_is_deleted`.
     */
    protected function preloadMaps(): void
    {
        $companyId = $this->company->getId();

        // Lookup tables — all stored under INTRAS_EVENT_ID per the import convention.
        $this->idMaps[EventType::class] = $this->loadIntrasMap($companyId, EventType::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[EventClass::class] = $this->loadIntrasMap($companyId, EventClass::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[EventCategory::class] = $this->loadIntrasMap($companyId, EventCategory::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[EventStatus::class] = $this->loadIntrasMap($companyId, EventStatus::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[Theme::class] = $this->loadIntrasMap($companyId, Theme::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[ThemeArea::class] = $this->loadIntrasMap($companyId, ThemeArea::class, CustomFieldEnum::INTRAS_EVENT_ID->value);

        // Parents — populated incrementally during the run too, but seed from any
        // already-imported rows so re-runs / partial pulls work without duplicate
        // creates.
        $this->idMaps[Event::class] = $this->loadIntrasMap($companyId, Event::class, CustomFieldEnum::INTRAS_EVENT_ID->value);
        $this->idMaps[EventVersion::class] = $this->loadIntrasMap($companyId, EventVersion::class, CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value);
    }

    /**
     * @return array<int|string, int>
     */
    protected function loadIntrasMap(int $companyId, string $modelClass, string $customFieldName): array
    {
        return DB::connection('ecosystem')
            ->table('apps_custom_fields')
            ->where('companies_id', $companyId)
            ->where('model_name', $modelClass)
            ->where('name', $customFieldName)
            ->where('is_deleted', 0)
            ->pluck('entity_id', 'value')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function mapId(string $modelClass, ?int $intrasId): ?int
    {
        if ($intrasId === null) {
            return null;
        }

        return $this->idMaps[$modelClass][$intrasId] ?? null;
    }
}
