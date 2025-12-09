<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Inventory\Variants\Models\VariantsAttributes;

class VariantsAttributesObserver
{
    public function saved(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function deleted(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }
}
