<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Shopify\Jobs\ImportProducts;
use Kanvas\Inventory\Importer\Actions\ImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Importer\Jobs\ProductImporterJob as ImporterJob;

class Import
{
    /**
     * importer
     *
     * @param  mixed $root
     * @param  mixed $req
     * @return bool
     */
    public function product(mixed $root, array $req): bool
    {
        $dto = ProductImporter::from($req['input']);
        ImporterJob::dispatchSync($req['source'], $dto, auth()->user()->defaultCompany);
        return true;
    }
}
