<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class BuildEventDataAction
{
    public function __construct(
        private Model $resource,
        private UserInterface $user,
        private CompanyInterface $company,
        private array $input,
    ) {
    }

    public function execute(): array
    {
        $input = $this->input;
        $resource = $this->resource;
        $user = $this->user;
        $company = $this->company;
        $app = $resource->app;

        $startAt = Carbon::parse($input['start_at']);
        $endAt = Carbon::parse($input['end_at']);
        $eventName = $input['event_name'] ?? $resource->name . $startAt->format('Y-m-d H:i');

        $eventType = EventType::firstOrCreate([
            'companies_id' => $resource->company->getId(),
            'apps_id' => $resource->app->getId(),
            'name' => EventStatusEnum::DEFAULT->value,
        ], [
            'users_id' => $user->getId(),
        ]);

        $eventClass = EventClass::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'name' => EventStatusEnum::DEFAULT->value,
        ], [
            'users_id' => $user->getId(),
        ]);

        $eventCategory =  EventCategory::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'event_type_id' => $eventType?->id,
            'event_class_id' => $eventClass?->id,
            'name' => EventStatusEnum::DEFAULT->value,
        ], [
            'users_id' => $user->getId(),
        ]);

        $theme = Theme::firstOrCreate([
            'name' => EventStatusEnum::DEFAULT->value,
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
        ]);

        $themeArea = ThemeArea::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'name' => EventStatusEnum::DEFAULT->value,
        ], [
            'users_id' => $user->getId(),
        ]);

        $eventStatus = EventStatus::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'name' => EventStatusEnum::DEFAULT->value,
        ], [
            'users_id' => $user->getId(),
        ]);

        return [
            'name' => $eventName,
            'description' => $input['event_description'] ?? $resource->description,
            'slug' => Str::simpleSlug($eventName),
            'resources_id' => (string) $resource->id,
            'resources_type' => $resource->getMorphClass(),
            'participants' => $input['participants'],
            'resources' => $input['resources'] ?? [],
            'time_slot_id' => $input['time_slot_id'] ?? null,
            'dates' => [
                [
                    'date' => $startAt->toDateString(),
                    'start_time' => $startAt->format('H:i'),
                    'end_time' => $endAt->format('H:i'),
                ]
            ],
            'theme_id' => (string) $theme?->id,
            'theme_area_id' => (string) $themeArea?->id,
            'status_id' => (string) $eventStatus?->id,
            'type_id' => $eventType?->id,
            'class_id' => (string) $eventClass?->id,
            'category_id' => $eventCategory?->id,
        ];
    }
}
