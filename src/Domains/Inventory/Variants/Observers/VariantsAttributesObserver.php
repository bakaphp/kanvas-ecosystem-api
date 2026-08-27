<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Observers;

use Kanvas\Inventory\Variants\Events\VariantSearchDocumentChanged;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

class VariantsAttributesObserver
{
    public function created(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function updated(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
    }

    public function saved(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
        $this->reindexInterests($variantAttribute);
    }

    public function deleted(VariantsAttributes $variantAttribute): void
    {
        $variantAttribute->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
        $this->reindexInterests($variantAttribute);
    }

    private function reindexInterests(VariantsAttributes $variantAttribute): void
    {
        if ($variantAttribute->variant !== null) {
            VariantSearchDocumentChanged::dispatchFor($variantAttribute->variant);
        }
    }
}
