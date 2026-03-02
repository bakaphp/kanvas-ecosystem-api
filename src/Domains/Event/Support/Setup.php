<?php

declare(strict_types=1);

namespace Kanvas\Event\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Enums\EventSetupTypeEnum;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class Setup
{
    /** @var array<int, array<string, mixed>> */
    protected array $themes = [
        ['name' => 'Business', 'setup' => 'standard'],
        ['name' => 'Corporate', 'setup' => 'full'],
        ['name' => 'Wedding', 'setup' => 'full'],
        ['name' => 'Birthday', 'setup' => 'full'],
        ['name' => 'Conference', 'setup' => 'full'],
        ['name' => 'Charity', 'setup' => 'full'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $themeAreas = [
        ['name' => 'Indoor', 'setup' => 'standard'],
        ['name' => 'Outdoor', 'setup' => 'full'],
        ['name' => 'Virtual', 'setup' => 'full'],
        ['name' => 'Hybrid', 'setup' => 'full'],
        ['name' => 'Beach', 'setup' => 'full'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $participantTypes = [
        ['name' => 'Attendee', 'setup' => 'standard'],
        ['name' => 'Speaker', 'setup' => 'full'],
        ['name' => 'Sponsor', 'setup' => 'full'],
        ['name' => 'Exhibitor', 'setup' => 'full'],
        ['name' => 'Volunteer', 'setup' => 'full'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $eventTypes = [
        ['name' => 'Seminar', 'setup' => 'full'],
        ['name' => 'Workshop', 'setup' => 'full'],
        ['name' => 'Webinar', 'setup' => 'full'],
        ['name' => 'Networking', 'setup' => 'full'],
        ['name' => 'Festival', 'setup' => 'full'],
        ['name' => 'Appointment', 'setup' => 'standard'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $eventStatuses = [
        ['name' => 'Upcoming', 'setup' => 'standard'],
        ['name' => 'Ongoing', 'setup' => 'standard'],
        ['name' => 'Completed', 'setup' => 'standard'],
        ['name' => 'Cancelled', 'setup' => 'standard'],
        ['name' => 'Postponed', 'setup' => 'standard'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $eventClasses = [
        ['name' => 'Free', 'setup' => 'standard'],
        ['name' => 'Paid', 'setup' => 'full'],
        ['name' => 'VIP', 'setup' => 'full'],
        ['name' => 'Exclusive', 'setup' => 'full'],
        ['name' => 'Public', 'setup' => 'full'],
    ];

    /** @var array<int, array<string, mixed>> */
    protected array $categories = [
        ['type' => 'Seminar', 'class' => 'Free', 'category' => 'Educational', 'setup' => 'full'],
        ['type' => 'Workshop', 'class' => 'Paid', 'category' => 'Skill Development', 'setup' => 'full'],
        ['type' => 'Webinar', 'class' => 'VIP', 'category' => 'Exclusive Knowledge', 'setup' => 'full'],
        ['type' => 'Networking', 'class' => 'Public', 'category' => 'Professional Growth', 'setup' => 'full'],
        ['type' => 'Festival', 'class' => 'Exclusive', 'category' => 'Entertainment', 'setup' => 'full'],
        ['type' => 'Appointment', 'class' => 'Free', 'category' => 'Appointment', 'is_default' => 1, 'setup' => 'standard'],
    ];

    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company,
        protected EventSetupTypeEnum $setupType = EventSetupTypeEnum::STANDARD
    ) {
    }

    public function run(): bool
    {
        new CreateSystemModule($this->app)->run();

        foreach ($this->filterBySetupType($this->themes) as $key => $theme) {
            Theme::firstOrCreate([
                'name' => $theme['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->filterBySetupType($this->themeAreas) as $key => $area) {
            ThemeArea::firstOrCreate([
                'name' => $area['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->filterBySetupType($this->participantTypes) as $type) {
            ParticipantType::firstOrCreate([
                'name' => $type['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
            ]);
        }

        foreach ($this->filterBySetupType($this->eventTypes) as $type) {
            EventType::firstOrCreate([
                'name' => $type['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
            ]);
        }

        foreach ($this->filterBySetupType($this->eventStatuses) as $key => $status) {
            EventStatus::firstOrCreate([
                'name' => $status['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->filterBySetupType($this->eventClasses) as $key => $class) {
            EventClass::firstOrCreate([
                'name' => $class['name'],
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->filterBySetupType($this->categories) as $category) {
            EventCategory::firstOrCreate([
                'event_type_id' => EventType::fromApp($this->app)->fromCompany($this->company)->where('name', $category['type'])->first()->getId(),
                'event_class_id' => EventClass::fromApp($this->app)->fromCompany($this->company)->where('name', $category['class'])->first()->getId(),
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'name' => $category['category'],
                'is_default' => $category['is_default'] ?? 0,
            ]);
        }

        return EventType::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && EventStatus::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && EventClass::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && EventCategory::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && Theme::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && ThemeArea::fromApp($this->app)->fromCompany($this->company)->count() > 0
            && ParticipantType::fromApp($this->app)->fromCompany($this->company)->count() > 0;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    protected function filterBySetupType(array $items): array
    {
        if ($this->setupType === EventSetupTypeEnum::FULL) {
            return array_values($items);
        }

        return array_values(array_filter(
            $items,
            fn (array $item) => $item['setup'] === EventSetupTypeEnum::STANDARD->value
        ));
    }
}
