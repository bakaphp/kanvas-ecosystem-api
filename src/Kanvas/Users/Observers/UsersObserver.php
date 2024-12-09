<?php

declare(strict_types=1);

namespace Kanvas\Users\Observers;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UsersObserver
{
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
        /*  if ($user->isFirstSignup() && $user->createDefaultCompany()) {
             $createCompany = new CreateCompaniesAction(
                 new CompaniesPostData(
                     $user->defaultCompanyName ?? $user->displayname . 'CP',
                     $user->id,
                     $user->email
                 )
             );

             $company = $createCompany->execute();

             $user->default_company = (int) $company->getId();
             $user->default_company_branch = (int) $company->defaultBranch()->first()->getId();
             $user->saveOrFail();
         }

         if ($user->default_company) {
             $company = CompaniesRepository::getById($user->default_company);
             $branch = $company->branch()->firstOrFail();

             $action = new AssignCompanyAction($user, $branch);
             $action->execute();
         } */
    }

    public function updated(Users $user): void
    {
        //@todo for now , we are allowing this , but we have to move to just update appUserProfile
        $app = app(Apps::class);

        try {
            $appUser = $user->getAppProfile($app);
        } catch (ModelNotFoundException $e) {
            $userRegisterInApp = new RegisterUsersAppAction($user, $app);
            $appUser = $userRegisterInApp->execute($user->password);
        }
        $appUser->update([
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'displayname' => $user->displayname,
            'email' => $user->email,
        ]);

        $user->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            ['company' => $user->getCurrentCompany()]
        );
    }
}
