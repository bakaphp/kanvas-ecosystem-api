<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Kanvas\Users\Models\UserCompanyApps;

class UsersAssociatedCompaniesObserver
{
    public function created(UserCompanyApps $userCompanyApps)
    {
        $userCompanyApps->app->set('total_companies', $userCompanyApps->apps->companies->count());
    }

    public function updated(UserCompanyApps $userCompanyApps)
    {
        $userCompanyApps->app->set('total_companies', $userCompanyApps->apps->companies->count());
    }

    public function deleted(UserCompanyApps $userCompanyApps)
    {
        $userCompanyApps->app->set('total_companies', $userCompanyApps->apps->companies->count());
    }
}
