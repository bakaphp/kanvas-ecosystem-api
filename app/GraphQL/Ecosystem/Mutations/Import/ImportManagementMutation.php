<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Import;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\ImportersRequests\DataTransferObject\ImporterRequest as ImporterRequest;
use Kanvas\ImportersRequests\Actions\CreateImporterRequestAction;
use Kanvas\ImportersRequests\Actions\ImporterFromMapperAction;
use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;

class ImportManagementMutation
{

    public function import(mixed $root, array $req): string
    {
        $company = Companies::getById($req['companyId']);

        CompaniesRepository::userAssociatedToCompany(
            $company,
            auth()->user()
        );

        $region = ! isset($req['regionId']) ? Regions::getDefault($company) : RegionRepository::getById($req['regionId'], $company);
        $importRequestDto = new ImporterRequest(
            app(Apps::class),
            $company->branch,
            auth()->user(),
            $region,
            $company,
            $req['input'],
        );
        $importerRequest = (new CreateImporterRequestAction($importRequestDto))->executer();
        $mapper = MapperImporterTemplate::getById($req['mapperId']);
        (new ImporterFromMapperAction($importerRequest, $mapper))->execute();
        return $importerRequest->uuid;
    }
}
