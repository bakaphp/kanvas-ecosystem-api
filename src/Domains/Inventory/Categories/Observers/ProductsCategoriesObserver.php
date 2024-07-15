<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Observers;

use Kanvas\Inventory\Products\Models\ProductsCategories;

class ProductsCategoriesObserver
{
    public function saved(ProductsCategories $productsCategories): void
    {
        $productsCategories->category->set(
            'total_products',
            $productsCategories->category->getTotalProducts()
        );
    }
}
