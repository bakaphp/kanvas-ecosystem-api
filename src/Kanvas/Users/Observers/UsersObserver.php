<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;

class UsersObserver
{
    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     */
    public function creating(Users $user): void
    {
        $user->uuid = Str::uuid()->toString();
        //$user->system_modules_id = SystemModules::first()->id;
    }

    /**
     * After Create.
     */
    public function created(Users $user): void
    {
        if ($user->isFirstSignup()) {
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
        }

        $company = CompaniesRepository::getById((int)$user->default_company);
        $branch = $company->branch()->firstOrFail();

        $action = new AssignCompanyAction($user, $branch);
        $action->execute();
    }
}
