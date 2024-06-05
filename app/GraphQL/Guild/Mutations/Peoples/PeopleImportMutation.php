<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Peoples;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Guild\Customers\Jobs\CustomerImporterJob;

class PeopleImportMutation
{
    /**
     * Create new customer
     */
    public function importPeople(mixed $root, array $req): string
    {
        $user = auth()->user();
        $company = Companies::getById($req['companyId']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            $user
        );

        $jobUuid = Str::uuid()->toString();

        CustomerImporterJob::dispatch(
            $jobUuid,
            $req['input'],
            $company->branch,
            $user,
            app(Apps::class)
        );
        return $jobUuid;
    }
}
