<?php
declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\CompaniesPostData;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Actions\AssignRole;
use Kanvas\Users\Models\Users;

class UsersObserver
{

    /**
     * Handle the Apps "saving" event.
     *
     * @param  Apps $app
     *
     * @return void
     */
    public function creating(Users $user) : void
    {
        $user->uuid = Str::uuid()->toString();
        //$user->system_modules_id = SystemModules::first()->id;
    }

    /**
     * After Create.
     *
     * @param Users $user
     *
     * @return void
     */
    public function created(Users $user) : void
    {
        $app = app(Apps::class);

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
        } else {
            $company = CompaniesRepository::getById($user->default_company);
            $branch = $company->branch()->first();

            if (!$user->get(Companies::cacheKey())) {
                $user->set(Companies::cacheKey(), $company->id);
            }

            if (!$user->get($company->branchCacheKey())) {
                $user->set($company->branchCacheKey(), $branch->id);
            }

            $company->associateUser(
                $user,
                StateEnums::ON->getValue(),
                $branch
            );
        }

        $company->associateUserApp(
            $user,
            $app,
            StateEnums::ON->getValue()
        );

        if (!$role = $app->get(AppEnums::DEFAULT_ROLE_SETTING->getValue())) {
            $role = $app->name . '.' . $user->role()->first()->name;
        }

        $assignRole = new AssignRole($user, $company, $app);
        $assignRole->execute($role);
    }
}
