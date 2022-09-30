<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Apps\Apps\Observers\AppsObserver;
use Kanvas\CompanyGroup\Companies\Events\AfterSignupEvent;
use Kanvas\CompanyGroup\Companies\Listeners\AfterSignupListener;
use Kanvas\CompanyGroup\Companies\Models\Companies;
use Kanvas\CompanyGroup\Companies\Observers\CompaniesObserver;
use Kanvas\CompanyGroup\Groups\Models\CompaniesGroups;
use Kanvas\CompanyGroup\Groups\Observers\CompaniesGroupsObserver;
use Kanvas\UsersGroup\Users\Models\Users;
use Kanvas\UsersGroup\Users\Observers\UsersObserver;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        AfterSignupEvent::class => [
            AfterSignupListener::class,
        ]
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        Apps::observe(AppsObserver::class);
        Users::observe(UsersObserver::class);
        Companies::observe(CompaniesObserver::class);
        CompaniesGroups::observe(CompaniesGroupsObserver::class);
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return true;
    }
}
