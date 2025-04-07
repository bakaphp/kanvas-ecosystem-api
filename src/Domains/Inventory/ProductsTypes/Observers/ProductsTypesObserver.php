<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class ProductsTypesObserver
{
    public function deleting(ProductsTypes $productType): void
    {
        if ($productType->hasDependencies()) {
            throw new ValidationException('Can\'t delete, ProductType has products associated');
        }
    }
}
