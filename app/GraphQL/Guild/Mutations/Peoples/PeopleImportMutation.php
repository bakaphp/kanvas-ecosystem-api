<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Customers\Jobs\CustomerImporterJob;
use Kanvas\Regions\Models\Regions;

class PeopleImportMutation
{
    /**
     * Create new customer
     */
    public function import(mixed $root, array $req): string
    {
        $user = auth()->user();
        $company = isset($req['companyId']) ? Companies::getById($req['companyId']) : $user->getCurrentCompany();
        $app = app(Apps::class);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            $user
        );

        $jobUuid = Str::uuid()->toString();
        $region = Regions::getDefault($company, $app);

        CustomerImporterJob::dispatch(
            $jobUuid,
            $req['input'],
            $company->branch,
            $user,
            $region,
            app(Apps::class)
        );

        return $jobUuid;
    }
}
