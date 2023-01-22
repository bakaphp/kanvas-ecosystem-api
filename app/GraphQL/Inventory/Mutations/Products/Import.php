<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

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
     * @return bool
     */
    public function product(mixed $root, array $req) : bool
    {
        $region = RegionRepository::getById($req['regionId'], auth()->user()->getCurrent);

        //verify it has the correct format
        ProductImporter::from($req['input'][0]);

        ImporterJob::dispatch(
            $req['input'],
            auth()->user()->getCurrentCompany(),
            auth()->user(),
            $region
        );
        return true;
    }
}
