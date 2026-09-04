<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Kanvas\Users\Models\UserCompanyApps;

class UsersAssociatedCompaniesObserver
{
    public function created(UserCompanyApps $userCompanyApps): void
    {
        $this->refreshTotal($userCompanyApps);
    }

    public function updated(UserCompanyApps $userCompanyApps): void
    {
        $this->refreshTotal($userCompanyApps);
    }

    public function deleted(UserCompanyApps $userCompanyApps): void
    {
        $this->refreshTotal($userCompanyApps);
    }

    /**
     * `companies()->count()` — the relation, not the collection.
     *
     * `$app->companies->count()` hydrates every Company on the app to count them, and this fires on
     * every user-company association. On an app with ~51k companies that is an OOM, and it happens
     * inside whatever create triggered it. One COUNT(*) answers the same question.
     */
    private function refreshTotal(UserCompanyApps $userCompanyApps): void
    {
        $app = $userCompanyApps->app;

        $app->set('total_companies', $app->companies()->count());
    }
}
