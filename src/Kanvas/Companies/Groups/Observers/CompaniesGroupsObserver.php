<?php

namespace Kanvas\Companies\Groups\Observers;

use Illuminate\Support\Str;
use Kanvas\Companies\Models\CompaniesGroups;

class CompaniesGroupsObserver
{
    /**
     * Handle the "saving" event.
     *
     * @param  CompaniesGroups $companyGroup
     *
     * @return void
     */
    public function saving(CompaniesGroups $companyGroup): void
    {
        $companyGroup->uuid = Str::uuid()->toString();
    }
}
