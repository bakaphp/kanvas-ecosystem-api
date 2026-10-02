<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Mappers\EventVersionMapper;
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
use Throwable;

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
        new RestoreDeletedParentEventsAction($this->app, $this->company)->execute();
        $this->pullEventVersionDates($client, $counts);

        return $counts;
    }

    protected function pullEvents(Client $client, array &$counts): void
    {
        $query = $client->table('events');

        if ($this->agencyId !== null) {
            $agencyId = $this->agencyId;

            // An agency's events are not just `events.agencies_id = X`. A live version can hang
            // off an event that is soft-deleted, or off one belonging to a *different* agency —
            // and `pullEventVersions()` drops any version whose parent was not imported, taking
            // its registrations with it. Silently: 6 versions and 69 registrations across the
            // four agencies, which is exactly the kind of loss that only surfaces when someone
            // reconciles against SIPGO at cutover.
            $query->where(function (QueryBuilder $q) use ($agencyId): void {
                $q->where(fn (QueryBuilder $own) => $own->where('agencies_id', $agencyId)->where('is_deleted', 0))
                    ->orWhereIn('id', function (QueryBuilder $sub) use ($agencyId): void {
                        $sub->select('events_id')
                            ->from('events_versions')
                            ->where('agencies_id', $agencyId)
                            ->where('is_deleted', 0);
                    });
            });
        } else {
            $query->where('is_deleted', 0);
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

        $aliadoNames = self::loadVersionLookupNames($client)['affiliates'] ?? [];

        $query->orderBy('id')->chunk(500, function ($rows) use (&$counts, $defaultType, $defaultClass, $defaultCategory, $defaultStatus, $defaultTheme, $defaultThemeArea, $aliadoNames) {
            foreach ($rows as $row) {
                $eventTypeId = $this->mapId(EventType::class, $row->events_types_id) ?? $defaultType?->getId();
                $eventClassId = $this->mapId(EventClass::class, $row->events_classes_id) ?? $defaultClass?->getId();
                $eventCategoryId = $this->mapId(EventCategory::class, $row->events_categories_id) ?? $defaultCategory?->getId();
                $eventStatusId = $this->mapId(EventStatus::class, $row->events_statuses_id) ?? $defaultStatus?->getId();
                $themeId = $this->mapId(Theme::class, $row->themes_id) ?? $defaultTheme?->getId();
                $themeAreaId = $this->mapId(ThemeArea::class, $row->themes_areas_id) ?? $defaultThemeArea?->getId();

                $slug = Str::slug(trim($row->name) . '-' . $row->id);

                // Always stored live: an event deleted in SIPGO is only pulled because it parents
                // a live version, and a live version under a deleted event breaks the non-null
                // `EventVersion.event` (KANVAS-ECOSYSTEM-5GS). withTrashed() still finds the rows
                // earlier runs stored flagged, which RestoreDeletedParentEventsAction un-deletes.
                $event = Event::withTrashed()->firstOrCreate([
                    'slug' => $slug,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'is_deleted' => 0,
                    'name' => trim($row->name),
                    'event_type_id' => $eventTypeId,
                    'event_class_id' => $eventClassId,
                    'event_category_id' => $eventCategoryId,
                    'event_status_id' => $eventStatusId,
                    'theme_id' => $themeId,
                    'theme_area_id' => $themeAreaId,
                    // Real Kanvas columns that were simply never written. `classification` is the
                    // one the deleted EventMapper mapped and the inline code did not, which is
                    // why it was left null on every imported event.
                    'classification' => $row->classification ?? null,
                    'versions_count' => $row->versions_count ?? 0,
                    'participants_average' => $row->participants_average ?? 0,
                    'participants_satisfaction' => $row->participants_satisfaction ?? 0,
                ]);

                $event->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
                $event->set(CustomFieldEnum::INTRAS_AGENCY_ID->value, $row->agencies_id);

                // No Kanvas column for the affiliate; the Eventos tab filters it by name.
                $aliado = Str::trimToNull((string) ($aliadoNames[(int) ($row->affiliates_id ?? 0)] ?? ''));

                if ($aliado !== null) {
                    $event->set('aliado', $aliado);
                }

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

        // events_versions.currencies_id was being ignored, so every version imported as USD —
        // wrong for the RD$ ones, and the cost report's FX conversion keys off this.
        $currencyIdMap = self::loadCurrencyMap($client, $defaultCurrency?->getId());

        $versionLookupNames = self::loadVersionLookupNames($client);
        $placeGeo = self::loadPlaceGeo($client);
        $defaultStatus = EventStatus::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();
        $versionStatusMap = $this->resolveVersionStatuses($client);

        $query->orderBy('id')->chunk(500, function ($rows) use (&$counts, $defaultCurrency, $currencyIdMap, $versionLookupNames, $placeGeo, $defaultStatus, $versionStatusMap) {
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
                    'currency_id' => $currencyIdMap[(int) ($row->currencies_id ?? 0)]
                        ?? $defaultCurrency?->getId(),
                    'event_status_id' => $versionStatusMap[(int) ($row->events_versions_statuses_id ?? 0)]
                        ?? $defaultStatus?->getId(),
                    'places_comments' => $row->places_comments ?? null,
                    'participants_satisfaction' => $row->participants_satisfaction ?? 0,
                    'metadata' => EventVersionMapper::metadata($row),
                ]);

                $eventVersion->set(CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value, $row->id);

                // Status is the one attribute that legitimately moves after the version exists —
                // Pendiente becomes Completado, or Cancelado — so it is re-applied on every run
                // instead of being frozen at whatever the first import happened to see.
                $statusId = $versionStatusMap[(int) ($row->events_versions_statuses_id ?? 0)] ?? null;

                if ($statusId !== null && (int) $eventVersion->event_status_id !== $statusId) {
                    $eventVersion->event_status_id = $statusId;
                    $eventVersion->saveQuietly();
                }

                foreach (EventVersionMapper::customFields($row, $versionLookupNames, $placeGeo) as $key => $value) {
                    $eventVersion->set($key, $value);
                }

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

        $this->backfillVersionDateRange();
    }

    /**
     * Derive each version's `start_at` / `end_at` from its imported dates.
     *
     * SIPGO keeps no date range on `events_versions` — only the child `events_versions_dates`
     * rows — so the columns were left null on every version. That is not cosmetic:
     * `ParticipantPass::scopeNoShow()` filters on `start_at`, the Gestor's event date range is a
     * version-level filter, and `rpt_inscripcion.fecha_inicio` / `fecha_fin` read from it.
     *
     * One grouped query plus one UPDATE per version, rather than a query per version.
     */
    protected function backfillVersionDateRange(): void
    {
        $versionIds = array_values($this->idMaps[EventVersion::class] ?? []);

        if ($versionIds === []) {
            return;
        }

        $ranges = EventVersionDate::query()
            ->whereIn('event_version_id', $versionIds)
            ->where('is_deleted', 0)
            ->groupBy('event_version_id')
            ->selectRaw('event_version_id, MIN(event_date) as starts, MAX(event_date) as ends')
            ->get();

        foreach ($ranges as $range) {
            EventVersion::where('id', $range->event_version_id)->update([
                'start_at' => $range->starts,
                'end_at' => $range->ends,
            ]);
        }
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

    /**
     * [legacy currencies.id => kanvas currencies.id], matched on ISO code.
     *
     * A legacy currency whose code Kanvas does not carry falls back to the default rather than
     * importing a null — a version with no currency breaks every money column downstream.
     *
     * @return array<int, int|null>
     */
    public static function loadCurrencyMap(Client $client, ?int $fallbackId): array
    {
        $kanvasByCode = Currencies::pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtoupper(trim((string) $code)) => (int) $id])
            ->all();

        $map = [];

        foreach ($client->table('currencies')->get() as $row) {
            $code = mb_strtoupper(trim((string) ($row->code ?? '')));
            $map[(int) $row->id] = $kanvasByCode[$code] ?? $fallbackId;
        }

        return $map;
    }

    /**
     * Catalogs behind a version FK that Kanvas does not model — their names are stored as custom
     * fields. A catalog missing on an agency's install drops that field, not the import.
     *
     * @return array<string, array<int, string>>
     */
    /**
     * [legacy events_versions_statuses.id => kanvas event_statuses.id]
     *
     * Versions have their own status vocabulary — Pendiente, REALIZADO, Completado, Cancelado,
     * En Progreso, Correspondencia — in a *different table* from the event-level
     * `events_statuses` the lookup sync handles. Resolving the version's id through the event
     * catalog silently matched ids 1-2 to the wrong status and sent everything else to the
     * default, so all 3,053 of agency 1's versions imported as ACTIVO and its 303 cancellations
     * vanished — along with any way to exclude the 1,925 registrations sitting on them.
     *
     * Matched on name rather than a legacy-id custom field because two legacy rows are both
     * called "Pendiente" (ids 1 and 5): `firstOrCreate` by name points both at one Kanvas row,
     * where a single custom field would keep only the last id and drop the other.
     *
     * @return array<int, int>
     */
    protected function resolveVersionStatuses(Client $client): array
    {
        $idByName = [];
        $map = [];

        foreach ($client->table('events_versions_statuses')->get() as $row) {
            $name = Str::trimToNull((string) $row->name);

            if ($name === null) {
                continue;
            }

            $idByName[$name] ??= (int) EventStatus::firstOrCreate([
                'name' => $name,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ])->getId();

            $map[(int) $row->id] = $idByName[$name];
        }

        return $map;
    }

    public static function loadVersionLookupNames(Client $client): array
    {
        $names = [];

        foreach (EventVersionMapper::lookupTables() as $table) {
            try {
                $names[$table] = $client->table($table)
                    ->pluck('name', 'id')
                    ->map(fn ($name) => trim((string) $name))
                    ->all();
            } catch (Throwable) {
                $names[$table] = [];
            }
        }

        return $names;
    }

    /**
     * [places.id => resolved country/city names].
     *
     * The Eventos tab reaches event geography through the venue
     * (`...placesareas.places.countries_id`), so the version needs it flattened onto itself.
     *
     * @return array<int, array{pais: ?string, ciudad: ?string}>
     */
    public static function loadPlaceGeo(Client $client): array
    {
        try {
            $countries = $client->table('countries')->pluck('name', 'id')->all();
            $cities = $client->table('cities')->pluck('name', 'id')->all();
            $places = $client->table('places')->select('id', 'countries_id', 'cities_id')->get();
        } catch (Throwable) {
            return [];
        }

        $geo = [];

        foreach ($places as $place) {
            $geo[(int) $place->id] = [
                'pais' => Str::trimToNull((string) ($countries[(int) ($place->countries_id ?? 0)] ?? '')),
                'ciudad' => Str::trimToNull((string) ($cities[(int) ($place->cities_id ?? 0)] ?? '')),
            ];
        }

        return $geo;
    }

    protected function mapId(string $modelClass, ?int $intrasId): ?int
    {
        if ($intrasId === null) {
            return null;
        }

        return $this->idMaps[$modelClass][$intrasId] ?? null;
    }
}
