<?php

namespace Kanvas\Companies\Companies\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Events\AfterSignupEvent;
use Kanvas\Companies\Companies\Repositories\CompaniesRepository;
use Kanvas\Companies\Companies\Models\Companies;
use Illuminate\Http\Request;

class CompaniesObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Companies $company
     *
     * @return void
     */
    public function creating(Companies $company) : void
    {
        $user = resolve('userData');
        $company->uuid = Str::uuid()->toString();
        $company->users_id = $user->first()->getKey();
        $company->is_deleted = 0;
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Companies $company
     *
     * @return void
     */
    public function created(Companies $company) : void
    {
        $userData = resolve('userData');
        CompaniesRepository::createBranch($company);
        // AfterSignupEvent::dispatch($company, $userData);
    }
}
