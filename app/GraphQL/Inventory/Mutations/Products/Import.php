<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Shopify\Jobs\ImportProducts;
use Kanvas\Inventory\Importer\Actions\ImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\Importer;
use Kanvas\Inventory\Importer\Jobs\Importer as ImporterJob;

class Import
{
    /**
     * importer
     *
     * @param  mixed $root
     * @param  mixed $req
     * @return bool
     */
    public function importer(mixed $root, array $req): bool
    {
        $dto = Importer::from($req['input']);
        ImporterJob::dispatchSync($req['source'], $dto, auth()->user()->defaultCompany);
        return true;
    }
}
