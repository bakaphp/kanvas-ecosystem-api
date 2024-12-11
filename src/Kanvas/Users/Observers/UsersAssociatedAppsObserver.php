<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UserAppRepository;

class UsersAssociatedAppsObserver implements ShouldQueue
{
    public int $tries = 1;
    public int $timeout = 5;

    public function created(UsersAssociatedApps $userAssociatedApp)
    {
        $userAssociatedApp->app->set('total_users', UserAppRepository::getAllAppUsers($userAssociatedApp->app)->count());
    }

    public function updated(UsersAssociatedApps $userAssociatedApp)
    {
        $userAssociatedApp->app->set('total_users', UserAppRepository::getAllAppUsers($userAssociatedApp->app)->count());
    }

    public function deleted(UsersAssociatedApps $userAssociatedApp)
    {
        $userAssociatedApp->app->set('total_users', UserAppRepository::getAllAppUsers($userAssociatedApp->app)->count());
    }
}
