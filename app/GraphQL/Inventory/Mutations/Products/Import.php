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
        $region = RegionRepository::getById($req['input']['region_id'], auth()->user()->getCurrent);
        $dto = ProductImporter::from($req['input']);

        ImporterJob::dispatch(
            $dto,
            auth()->user()->getCurrentCompany(),
            auth()->user(),
            $region
        );
        return true;
    }
}
