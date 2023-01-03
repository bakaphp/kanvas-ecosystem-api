<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Shopify\Jobs\ImportProducts;

class Import
{
    public function importer(mixed $root, array $req): bool
    {
        ImportProducts::dispatch();
        return true;
    }
}
