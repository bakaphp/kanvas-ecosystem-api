<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
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
                $eventType = $this->findByIntrasId(EventType::class, $row->events_types_id) ?? $defaultType;
                $eventClass = $this->findByIntrasId(EventClass::class, $row->events_classes_id) ?? $defaultClass;
                $eventCategory = $this->findByIntrasId(EventCategory::class, $row->events_categories_id) ?? $defaultCategory;
                $eventStatus = $this->findByIntrasId(EventStatus::class, $row->events_statuses_id) ?? $defaultStatus;
                $theme = $this->findByIntrasId(Theme::class, $row->themes_id) ?? $defaultTheme;
                $themeArea = $this->findByIntrasId(ThemeArea::class, $row->themes_areas_id) ?? $defaultThemeArea;

                $slug = Str::slug(trim($row->name) . '-' . $row->id);

                $event = Event::firstOrCreate([
                    'slug' => $slug,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'users_id' => $this->user->getId(),
                    'name' => trim($row->name),
                    'event_type_id' => $eventType?->getId(),
                    'event_class_id' => $eventClass?->getId(),
                    'event_category_id' => $eventCategory?->getId(),
                    'event_status_id' => $eventStatus?->getId(),
                    'theme_id' => $theme?->getId(),
                    'theme_area_id' => $themeArea?->getId(),
                ]);

                $event->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
                $event->set(CustomFieldEnum::INTRAS_AGENCY_ID->value, $row->agencies_id);
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
                $event = $this->findEventByIntrasId($row->events_id);
                if (! $event) {
                    continue;
                }

                $slug = Str::slug(trim($row->name) . '-v' . $row->version . '-' . $row->id);

                $eventVersion = EventVersion::firstOrCreate([
                    'slug' => $slug,
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->company->getId(),
                ], [
                    'event_id' => $event->getId(),
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
                $eventVersion = $this->findEventVersionByIntrasId($row->events_versions_id);
                if (! $eventVersion) {
                    continue;
                }

                EventVersionDate::firstOrCreate([
                    'event_version_id' => $eventVersion->getId(),
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

    protected function findByIntrasId(string $modelClass, ?int $intrasId): mixed
    {
        if ($intrasId === null) {
            return null;
        }

        return $modelClass::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_EVENT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findEventByIntrasId(?int $intrasId): ?Event
    {
        if ($intrasId === null) {
            return null;
        }

        return Event::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_EVENT_ID->value)->where('value', $intrasId)
            )
            ->first();
    }

    protected function findEventVersionByIntrasId(?int $intrasId): ?EventVersion
    {
        if ($intrasId === null) {
            return null;
        }

        return EventVersion::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->whereHas(
                'customFields',
                fn (Builder $q) => $q->where('name', CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value)->where('value', $intrasId)
            )
            ->first();
    }
}
