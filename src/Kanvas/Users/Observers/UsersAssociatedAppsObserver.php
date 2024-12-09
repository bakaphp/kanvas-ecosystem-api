<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Kanvas\Users\Models\UsersAssociatedApps;

class UsersAssociatedAppsObserver
{
    public function created(UsersAssociatedApps $userAssociatedApp)
    {
        //$userAssociatedApp->app->set('total_users', $userAssociatedApp->app->users->count());
    }

    public function updated(UsersAssociatedApps $userAssociatedApp)
    {
        //$userAssociatedApp->app->set('total_users', $userAssociatedApp->app->users->count());
    }

    public function deleted(UsersAssociatedApps $userAssociatedApp)
    {
        //$userAssociatedApp->app->set('total_users', $userAssociatedApp->app->users->count());
    }
}
