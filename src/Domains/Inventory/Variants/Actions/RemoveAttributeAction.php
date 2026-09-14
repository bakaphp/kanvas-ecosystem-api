<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

/**
 * Variant counterpart of the product RemoveAttributeAction. The removeAttributeToVariant mutation
 * used to call detach() on Variants::attributes(), which is a HasMany — see that action for why
 * the pivot row is deleted directly instead.
 */
class RemoveAttributeAction
{
    public function __construct(
        private Variants $variant,
        private Attributes $attribute
    ) {
    }

    public function execute(): Variants
    {
        VariantsAttributes::where('products_variants_id', $this->variant->getId())
            ->where('attributes_id', $this->attribute->getId())
            ->delete();

        $this->variant->clearLightHouseCache(withKanvasConfiguration: false);

        return $this->variant;
    }
}
