<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;

class ImportMutation
{
    /**
     * importer.
     *
     * @param  mixed $req
     */
    public function product(mixed $root, array $req): string
    {
        $company = Companies::getById($req['companyId']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $region = ! isset($req['regionId']) ? Regions::getDefault($company) : RegionRepository::getById($req['regionId'], $company);

        //verify it has the correct format
        ProductImporter::from($req['input'][0]);

        //so we can tie the job to pusher
        $jobUuid = Str::uuid()->toString();
        ImporterJob::dispatch(
            $jobUuid,
            $req['input'],
            $company->branch,
            auth()->user(),
            $region,
            app(Apps::class)
        );

        return $jobUuid;
    }
}
