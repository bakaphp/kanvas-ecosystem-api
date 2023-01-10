<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Shopify\Jobs\ImportProducts;
use Kanvas\Inventory\Importer\Actions\ImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\Importer;

class Import
{
    public function importer(mixed $root, array $req): bool
    {
        $dto = Importer::from([
            ...$req['input']['product'],
            'productType' => $req['input']['productType'],
            'categories' => $req['input']['categories'],
            'attributes'=> $req['input']['attributes'],
        ]);
        (new ImporterAction("telegram",$dto,auth()->user()->defaultCompany))->execute();
        return true;
    }
}
