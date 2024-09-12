<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Models\Products;

class ProductsObserver
{
    public function saved(Products $products): void
    {
        if ($products->wasChanged('products_types_id') && $products->productsTypes()->exists()) {
            $products->productsTypes->setTotalProducts();
        }

        $products->clearLightHouseCacheJob();
    }

    public function created(Products $products): void
    {
        if ($products->productsTypes()->exists()) {
            $products->productsTypes->setTotalProducts();
        }

        $products->clearLightHouseCacheJob();
    }
}
