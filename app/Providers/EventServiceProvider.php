<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Kanvas\Companies\Groups\Observers\CompaniesGroupsObserver;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesGroups;
use Kanvas\Companies\Observers\CompaniesObserver;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Observers\LeadObserver;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Observers\WarehouseObserver;
use Kanvas\Social\Messages\Models\UserMessageActivity;
use Kanvas\Social\Messages\Observers\UserMessageActivityObserver;
use Kanvas\Social\UsersLists\Models\UserList;
use Kanvas\Social\UsersLists\Observers\UsersListsObserver;
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
        Users::observe(UsersObserver::class);
        Companies::observe(CompaniesObserver::class);
        CompaniesGroups::observe(CompaniesGroupsObserver::class);
        UserMessageActivity::observe(UserMessageActivityObserver::class);
        UserList::observe(UsersListsObserver::class);
        Lead::observe(LeadObserver::class);
        Warehouses::observe(WarehouseObserver::class);
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
