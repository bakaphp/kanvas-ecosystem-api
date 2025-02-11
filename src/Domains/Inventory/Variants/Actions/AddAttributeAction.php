<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsAttributes;

class AddAttributeAction
{
    public function __construct(
        public Variants $variant,
        public Attributes $attribute,
        public mixed $value,
    ) {
    }

    public function execute(): Variants
    {
        if ($this->value === null || $this->value === '') {
            return $this->variant;
        }

        $variantAttribute = VariantsAttributes::where('products_variants_id', $this->variant->getId())
        ->where('attributes_id', $this->attribute->getId())
        ->first();

        if ($variantAttribute) {
            $variantAttribute->value = $this->value;
            $variantAttribute->update();
        } else {
            $variantAttribute = new VariantsAttributes();
            $variantAttribute->products_variants_id = $this->variant->getId();
            $variantAttribute->attributes_id = $this->attribute->getId();
            $variantAttribute->value = is_array($this->value) ? json_encode($this->value) : $this->value;
            $variantAttribute->save();
        }

        return $this->variant;
    }
}
