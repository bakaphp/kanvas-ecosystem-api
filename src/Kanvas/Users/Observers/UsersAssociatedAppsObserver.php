<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Contracts\Queue\ShouldQueue;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UserAppRepository;

class UsersAssociatedAppsObserver implements ShouldQueue
{
    /**
     * The columns UserAppRepository::getAllAppUsers() filters on. Every notification bumps
     * unread_notifications_count, so recounting on any update would rewrite the app-wide total_users row
     * once per notification, a hot row concurrent writers deadlock on.
     */
    private const array MEMBERSHIP_COLUMNS = ['apps_id', 'companies_id', 'is_deleted'];

    public int $tries = 1;
    public int $timeout = 5;

    public function created(UsersAssociatedApps $userAssociatedApp): void
    {
        $this->recountAppUsers($userAssociatedApp);
    }

    public function updated(UsersAssociatedApps $userAssociatedApp): void
    {
        if ($userAssociatedApp->wasChanged(self::MEMBERSHIP_COLUMNS)) {
            $this->recountAppUsers($userAssociatedApp);
        }
    }

    public function deleted(UsersAssociatedApps $userAssociatedApp): void
    {
        $this->recountAppUsers($userAssociatedApp);
    }

    private function recountAppUsers(UsersAssociatedApps $userAssociatedApp): void
    {
        $userAssociatedApp->app->set('total_users', UserAppRepository::getAllAppUsers($userAssociatedApp->app)->count());
    }
}
