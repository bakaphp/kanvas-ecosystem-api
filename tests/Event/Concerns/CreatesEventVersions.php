<?php

declare(strict_types=1);

namespace Tests\Event\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

trait CreatesEventVersions
{
    private function firstId(string $model): int
    {
        return $model::fromApp($this->apps)->fromCompany($this->company)->firstOrFail()->getId();
    }

    private function createEvent(?Companies $company = null): Event
    {
        return Event::create([
            'users_id' => $this->user->getId(),
            'companies_id' => ($company ?? $this->company)->getId(),
            'apps_id' => $this->apps->getId(),
            'theme_id' => $this->firstId(Theme::class),
            'theme_area_id' => $this->firstId(ThemeArea::class),
            'event_status_id' => $this->firstId(EventStatus::class),
            'event_type_id' => $this->firstId(EventType::class),
            'event_class_id' => $this->firstId(EventClass::class),
            'event_category_id' => $this->firstId(EventCategory::class),
            'name' => 'Redemption event ' . uniqid(),
        ]);
    }

    private function createEventVersion(Event $event, ?Companies $company = null, ?Carbon $startAt = null): EventVersion
    {
        return EventVersion::create([
            'event_id' => $event->getId(),
            'users_id' => $this->user->getId(),
            'companies_id' => ($company ?? $this->company)->getId(),
            'apps_id' => $this->apps->getId(),
            'currency_id' => 1,
            'name' => 'Redemption version ' . uniqid(),
            'version_number' => 1,
            'version' => '1',
            'price_per_ticket' => 0,
            'total_attendees' => 0,
            'start_at' => $startAt,
            'end_at' => $startAt?->copy()->addDay() ?? Carbon::now()->addDays(30),
        ]);
    }
}
