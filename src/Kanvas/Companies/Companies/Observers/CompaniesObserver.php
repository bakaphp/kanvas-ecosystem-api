<?php

namespace Kanvas\Companies\Companies\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Events\AfterSignupEvent;
use Kanvas\Companies\Companies\Repositories\CompaniesRepository;
use Kanvas\Companies\Companies\Models\Companies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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
        $user = Auth::user();
        $company->uuid = Str::uuid()->toString();
        $company->users_id = $user->id;
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
        $userData = Auth::user();
        CompaniesRepository::createBranch($company);
        // AfterSignupEvent::dispatch($company, $userData);
    }
}
