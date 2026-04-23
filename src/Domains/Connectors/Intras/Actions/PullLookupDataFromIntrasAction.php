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
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class PullLookupDataFromIntrasAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user,
        protected ?int $agencyId = null
    ) {
    }

    public function execute(): array
    {
        $client = new Client($this->app);
        $counts = [];

        $counts['event_types'] = $this->syncTable(
            $client,
            'events_types',
            EventType::class
        );

        $counts['event_statuses'] = $this->syncTable(
            $client,
            'events_statuses',
            EventStatus::class
        );

        $counts['themes'] = $this->syncTable(
            $client,
            'themes',
            Theme::class
        );

        $counts['theme_areas'] = $this->syncThemeAreas($client);

        $counts['event_classes'] = $this->syncEventClasses($client);

        $counts['event_categories'] = $this->syncEventCategories($client);

        $counts['participant_types'] = $this->syncParticipantTypes($client);

        return $counts;
    }

    protected function syncTable(Client $client, string $table, string $modelClass): int
    {
        $rows = $client->table($table)
            ->where('is_deleted', 0)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $model = $modelClass::firstOrCreate([
                'name' => trim($row->name),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ]);

            $model->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
            $count++;
        }

        return $count;
    }

    protected function syncThemeAreas(Client $client): int
    {
        $rows = $client->table('themes_areas')
            ->where('is_deleted', 0)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $themeArea = ThemeArea::firstOrCreate([
                'name' => trim($row->name),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ]);

            $themeArea->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
            $count++;
        }

        return $count;
    }

    protected function syncEventClasses(Client $client): int
    {
        $query = $client->table('events_classes')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        $rows = $query->get();

        $count = 0;
        foreach ($rows as $row) {
            $eventClass = EventClass::firstOrCreate([
                'name' => trim($row->name),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ]);

            $eventClass->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
            $count++;
        }

        return $count;
    }

    protected function syncEventCategories(Client $client): int
    {
        $query = $client->table('events_categories')
            ->where('is_deleted', 0);

        if ($this->agencyId !== null) {
            $query->where('agencies_id', $this->agencyId);
        }

        $rows = $query->get();

        $defaultEventType = EventType::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $defaultEventClass = EventClass::where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $count = 0;
        foreach ($rows as $row) {
            $eventType = $this->findByIntrasId(EventType::class, $row->events_types_id) ?? $defaultEventType;
            $eventClass = $this->findByIntrasId(EventClass::class, $row->events_classes_id) ?? $defaultEventClass;

            $slug = Str::slug(trim($row->name) . '-' . $row->id);

            $eventCategory = EventCategory::firstOrCreate([
                'slug' => $slug,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'name' => trim($row->name),
                'users_id' => $this->user->getId(),
                'event_type_id' => $eventType?->getId() ?? 0,
                'event_class_id' => $eventClass?->getId() ?? 0,
            ]);

            $eventCategory->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
            $count++;
        }

        return $count;
    }

    protected function syncParticipantTypes(Client $client): int
    {
        $rows = $client->table('inscriptions_types')
            ->where('is_deleted', 0)
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $participantType = ParticipantType::firstOrCreate([
                'name' => trim($row->name),
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ], [
                'users_id' => $this->user->getId(),
            ]);

            $participantType->set(CustomFieldEnum::INTRAS_EVENT_ID->value, $row->id);
            $count++;
        }

        return $count;
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
}
