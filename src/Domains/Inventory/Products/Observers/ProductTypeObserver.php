<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Jobs\SyncProductTypeAttributeJob;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductTypeObserver
{
    public function saved(ProductsTypes $productType): void
    {
        //SyncProductTypeAttributeJob::dispatch($productType);
    }
}
