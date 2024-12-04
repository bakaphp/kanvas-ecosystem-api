<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Models\Products;

class ProductsObserver
{
    public function saved(Products $product): void
    {
        if ($product->wasChanged('products_types_id') && $product->productsTypes()->exists()) {
            $product->productsTypes->setTotalProducts();
        }

        //$products->clearLightHouseCacheJob();
    }

    public function created(Products $products): void
    {
        if ($products->productsTypes()->exists()) {
            $products->productsTypes->setTotalProducts();
        }

        //$products->clearLightHouseCacheJob();
    }
}
