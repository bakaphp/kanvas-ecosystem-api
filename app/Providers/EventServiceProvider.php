<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Observers\AppsObserver;
use Kanvas\Companies\Groups\Observers\CompaniesGroupsObserver;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesGroups;
use Kanvas\Companies\Observers\CompaniesObserver;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Observers\UsersObserver;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [

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
