<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Repositories\UsersRepository;

final class AssignCompanyToApp
{
    /**
     * @param  null  $_
     * @param  array{}  $args
     */
    public function __invoke($_, array $request)
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
     * @param  null  $_
     * @param  array{}  $args
     */
    public function remove($_, array $request): Companies
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
}
