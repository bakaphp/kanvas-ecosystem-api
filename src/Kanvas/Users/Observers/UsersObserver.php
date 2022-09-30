<?php
declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Support\Str;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;

class UsersObserver
{
    /**
     * After Create.
     *
     * @param Users $user
     *
     * @return void
     */
    public function created(Users $user) : void
    {
        if ($user->isFirstSignup()) {
            //create company
            $createCompany = new CreateCompaniesAction(
                new CompaniesPostData(
                    $user->defaultCompanyName ?? $user->displayname . 'CP',
                    $user->id,
                    $user->email
                )
            );

            $company = $createCompany->execute();

            $user->default_company = $company->id;
            $user->default_company_branch = $company->defaultBranch()->first()->id;
            $user->saveOrFail();

            //set default values for current sesion
        } else {
        }
    }

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function saving(Users $user) : void
    {
        $user->uuid = Str::uuid()->toString();
        //$user->system_modules_id = SystemModules::first()->id;
    }
}
