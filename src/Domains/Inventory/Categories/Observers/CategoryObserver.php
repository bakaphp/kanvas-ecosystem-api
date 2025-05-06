<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Observers;

use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;

class CategoryObserver
{
    public function saved(Categories $category): void
    {
        $category->clearLightHouseCache(withKanvasConfiguration: false);
    }

    public function updating(Products $product)
    {
        if ($product->isDirty('users_id')) {
            $product->users_id = $product->getOriginal('users_id');
        }
    }
}
