<?php

namespace Kanvas\Companies\Companies\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Events\AfterSignupEvent;
use Kanvas\Companies\Companies\Models\Companies;

class CompaniesObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Companies $company
     *
     * @return void
     */
    public function saving(Companies $company) : void
    {
        $user = resolve('userData');
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
    public function saved(Companies $company) : void
    {
        $userData = resolve('userData');
        AfterSignupEvent::dispatch($company, $userData);
    }
}
