<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Observers;

use Kanvas\Inventory\Products\Models\ProductsAttributes;

class ProductsAttributesObserver
{
    public function created(ProductsAttributes $productAttribute): void
    {
        $productAttribute->product?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function updated(ProductsAttributes $productAttribute): void
    {
        $productAttribute->product?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function saved(ProductsAttributes $productAttribute): void
    {
        $productAttribute->product?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function deleted(ProductsAttributes $productAttribute): void
    {
        $productAttribute->product?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }
}
