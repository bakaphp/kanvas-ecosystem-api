<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Templates\Actions\CreateTemplateAction;
use Kanvas\Templates\DataTransferObject\TemplateInput;
use Kanvas\Users\Repositories\UsersRepository;

class AppCompanyMutation
{
    /**
     * assignCompanyToApp
     *
     * @param  array $req
     * @return void
     */
    public function assignCompanyToApp(mixed $root, array $request)
    {
        $id = $request['id'];
        $companyId = $request['companyId'];

        $app = AppsRepository::findFirstByKey($id);
        $company = CompaniesRepository::getByUuid($companyId);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);
        UsersRepository::belongsToCompany(auth()->user(), $company);

        //$action = new  CreateAppsAction($dto);
        $app->associateCompany($company);

        return $company;
    }

    /**
     * removeCompanyToApp
     * @param  null  $_
     * @param  array{}  $args
     */
    public function removeCompanyToApp($_, array $request): Companies
    {
        $id = $request['id'];
        $companyId = $request['companyId'];

        $app = AppsRepository::findFirstByKey($id);
        $company = CompaniesRepository::getByUuid($companyId);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        //if they are super user no need to verify if they belong to the company
        //UsersRepository::belongsToCompany(auth()->user(), $company);

        //$action = new  CreateAppsAction($dto);
        $app->associateCompany($company)->delete();

        return $company;
    }

    /**
     * createAppTemplate
     * @param  null  $_
     * @param  array{}  $args
     */
    public function createAppTemplate($_, array $request)
    {
        /**
         * @todo only super admin can do this
         */
        $app = AppsRepository::findFirstByKey($request['id']);

        UsersRepository::userOwnsThisApp(auth()->user(), $app);

        $createTemplate = new CreateTemplateAction(
            new TemplateInput(
                $app,
                $request['input']['name'],
                $request['input']['template'],
                null,
                auth()->user()
            )
        );

        return $createTemplate->execute();
    }
}
