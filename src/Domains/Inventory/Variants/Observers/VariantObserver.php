<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Inventory\Variants\Models\Variants;

class VariantObserver
{
    public function saved(Variants $variant): void
    {
        $variant->clearLightHouseCache();
    }
}
