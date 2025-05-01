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

        $product->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function created(Products $product): void
    {
        if ($product->productsTypes()->exists()) {
            $product->productsTypes->setTotalProducts();
        }

        $product->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function updating(Products $product)
    {
        if ($product->isDirty('users_id')) {
            $product->users_id = $product->getOriginal('users_id');
        }
    }
}
