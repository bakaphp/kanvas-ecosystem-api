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
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;

class Setup
{
    protected array $themes = [
        'Corporate',
        'Wedding',
        'Birthday',
        'Conference',
        'Charity',
    ];

    protected array $themArea = [
        'Outdoor',
        'Indoor',
        'Virtual',
        'Hybrid',
        'Beach',
    ];

    protected array $participantType = [
        'Attendee',
        'Speaker',
        'Sponsor',
        'Exhibitor',
        'Volunteer',
    ];

    protected array $eventType = [
        'Seminar',
        'Workshop',
        'Webinar',
        'Networking',
        'Festival',
    ];

    protected array $eventStatus = [
        'Upcoming',
        'Ongoing',
        'Completed',
        'Cancelled',
        'Postponed',
    ];

    protected array $eventClass = [
        'Free',
        'Paid',
        'VIP',
        'Exclusive',
        'Public',
    ];

    protected array $categories = [
        [
            'type' => 'Seminar',
            'class' => 'Free',
            'category' => 'Educational',
        ],
        [
            'type' => 'Workshop',
            'class' => 'Paid',
            'category' => 'Skill Development',
        ],
        [
            'type' => 'Webinar',
            'class' => 'VIP',
            'category' => 'Exclusive Knowledge',
        ],
        [
            'type' => 'Networking',
            'class' => 'Public',
            'category' => 'Professional Growth',
        ],
        [
            'type' => 'Festival',
            'class' => 'Exclusive',
            'category' => 'Entertainment',
        ],
    ];

    /**
     * Constructor.
     */
    public function __construct(
        protected AppInterface $app,
        protected UserInterface $user,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Setup all the default inventory data for this current company.
     */
    public function run(): bool
    {
        (new CreateSystemModule($this->app))->run();
        $default = 'Default';

        foreach ($this->themes as $key => $theme) {
            Theme::firstOrCreate([
                'name' => $theme,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->themArea as $key => $area) {
            ThemeArea::firstOrCreate([
                'name' => $area,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->participantType as $type) {
            ParticipantType::firstOrCreate([
                'name' => $type,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
            ]);
        }

        foreach ($this->eventType as $key => $type) {
            EventType::firstOrCreate([
                'name' => $type,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
            ]);
        }

        foreach ($this->eventStatus as $key => $status) {
            EventStatus::firstOrCreate([
                'name' => $status,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->eventClass as $key => $class) {
            EventClass::firstOrCreate([
                'name' => $class,
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'is_default' => (int) ($key == 0),
            ]);
        }

        foreach ($this->categories as $category) {
            EventCategory::firstOrCreate([
                'event_type_id' => EventType::fromApp($this->app)->fromCompany($this->company)->where('name', $category['type'])->first()->getId(),
                'event_class_id' => EventClass::fromApp($this->app)->fromCompany($this->company)->where('name', $category['class'])->first()->getId(),
                'companies_id' => $this->company->getId(),
                'apps_id' => $this->app->getId(),
                'users_id' => $this->user->getId(),
                'name' => $category['category'],
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
}
