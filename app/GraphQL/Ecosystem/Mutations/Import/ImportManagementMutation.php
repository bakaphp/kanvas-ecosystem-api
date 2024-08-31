<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Import;

use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\ImportersRequests\Actions\CreateImporterRequestAction;
use Kanvas\ImportersRequests\Actions\ImporterFromMapperAction;
use Kanvas\ImportersRequests\DataTransferObject\ImporterRequest as ImporterRequest;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\MappersImportersTemplates\Models\MapperImporterTemplate;

class ImportManagementMutation
{
    public function import(mixed $root, array $req): string
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $company = $branch->company;
        $app = app(Apps::class);

        $region = ! isset($req['regionId']) ? Regions::getDefault($company) : RegionRepository::getById($req['regionId'], $company);
        $filesystem = Filesystem::getByIdFromCompanyApp($req['filesystemId'], $company, $app);
        $importRequestDto = new ImporterRequest(
            $app,
            $branch,
            $user,
            $region,
            $company,
            $filesystem
        );

        $importerRequest = (new CreateImporterRequestAction($importRequestDto))->execute();
        $mapper = MapperImporterTemplate::getById($req['mapperId']);
        (new ImporterFromMapperAction($importerRequest, $mapper))->execute();

        return $importerRequest->uuid;
    }
}
