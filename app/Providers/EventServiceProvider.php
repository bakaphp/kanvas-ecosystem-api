<?php

declare(strict_types=1);

namespace Kanvas\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        \Kanvas\Companies\Companies\Events\AfterSignupEvent::class => [
            \Kanvas\Companies\Companies\Listeners\AfterSignupListener::class,
        ]
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        \Kanvas\Apps\Apps\Models\Apps::observe(
            \Kanvas\Apps\Apps\Observers\AppsObserver::class
        );

        \Kanvas\Users\Users\Models\Users::observe(
            \Kanvas\Users\Users\Observers\UsersObserver::class
        );

        \Kanvas\Companies\Companies\Models\Companies::observe(
            \Kanvas\Companies\Companies\Observers\CompaniesObserver::class
        );

        \Kanvas\Companies\Groups\Models\CompaniesGroups::observe(
            \Kanvas\Companies\Groups\Observers\CompaniesGroupsObserver::class
        );
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
