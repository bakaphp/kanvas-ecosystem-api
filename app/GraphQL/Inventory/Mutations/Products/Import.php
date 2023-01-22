<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Baka\Support\Str;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;

class Import
{
    /**
     * importer.
     *
     * @param  mixed $root
     * @param  mixed $req
     *
     * @return string
     */
    public function product(mixed $root, array $req) : string
    {
        $region = RegionRepository::getById($req['regionId'], auth()->user()->getCurrent);

        //verify it has the correct format
        ProductImporter::from($req['input'][0]);

        //so we can tie the job to pusher
        $jobUuid = Str::uuid()->toString();
        ImporterJob::dispatch(
            $jobUuid,
            $req['input'],
            auth()->user()->getCurrentCompany(),
            auth()->user(),
            $region
        );
        return $jobUuid;
    }
}
