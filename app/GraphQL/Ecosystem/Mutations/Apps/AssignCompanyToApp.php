<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Actions\CreateAppsAction;
use Exception;
use Kanvas\Apps\Repositories\AppsRepository;
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
        $companyId  = $request['companyId'];

        $app = AppsRepository::findFirstByKey($id);
        $company = CompaniesRepository::getByUuid($companyId);

        UsersRepository::belongsToThisApp(auth()->user(), $app);
        UsersRepository::belongsToCompany(auth()->user(), $company);

        //$action = new  CreateAppsAction($dto);
        $app->associateCompany($company);

        return $company;
    }
}
