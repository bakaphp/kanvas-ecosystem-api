<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Categories\Observers;

use Kanvas\Inventory\Categories\Models\Categories;

class CategoryObserver
{
    public function saved(Categories $category): void
    {
        $category->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
