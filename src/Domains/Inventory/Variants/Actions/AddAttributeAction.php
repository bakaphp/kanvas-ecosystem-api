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

        /**
         * Single atomic statement on purpose: a select-then-insert leaves a window where
         * two importer workers both miss and collide on the composite PK, which deadlocks
         * against the FK lock every insert holds on the shared `attributes` row.
         */
        VariantsAttributes::upsert(
            [$this->attributesToPersist()],
            ['products_variants_id', 'attributes_id'],
            ['value', 'is_deleted']
        );

        //upsert() bypasses model events, so VariantsAttributesObserver never runs
        $this->variant->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);

        return $this->variant;
    }

    /**
     * Build the row through the model so the translatable + JSON casts encode `value`
     * exactly the way a normal save would.
     */
    private function attributesToPersist(): array
    {
        $variantAttribute = new VariantsAttributes();
        $variantAttribute->products_variants_id = $this->variant->getId();
        $variantAttribute->attributes_id = $this->attribute->getId();
        $variantAttribute->value = $this->value;
        $variantAttribute->is_deleted = 0;

        return $variantAttribute->getAttributes();
    }
}
