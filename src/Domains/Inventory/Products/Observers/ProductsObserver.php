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

        $products->clearLightHouseCache();
    }

    public function created(Products $products): void
    {
        if ($products->productsTypes()->exists()) {
            $products->productsTypes->setTotalProducts();
        }

        $products->clearLightHouseCache();

        $products->load([
            'company',              // Load the company relationship
            'company.user',         // Load the user through the company
            'categories',           // Load categories
            'variants',             // Load variants
            'status',               // Load status
            'files',                // Load files (if it's a relationship)
            'attributes',           // Load attributes
        ]);
        
        // Call searchable after loading relationships
        $products->searchable();
    }
}
