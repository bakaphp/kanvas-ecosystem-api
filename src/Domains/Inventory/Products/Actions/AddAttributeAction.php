<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;

class AddAttributeAction
{
    public function __construct(
        private Products $product,
        private Attributes $attribute,
        private mixed $value
    ) {
    }

    public function execute(): Products
    {
        if ($this->value === null || $this->value === '') {
            return $this->product;
        }

        /**
         * Single atomic statement on purpose: a select-then-insert leaves a window where
         * two importer workers both miss and collide on the composite PK, which deadlocks
         * against the FK lock every insert holds on the shared `attributes` row.
         */
        ProductsAttributes::upsert(
            [$this->attributesToPersist()],
            ['products_id', 'attributes_id'],
            ['value']
        );

        //upsert() bypasses model events, so ProductsAttributesObserver never runs
        $this->product->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);

        return $this->product;
    }

    /**
     * Build the row through the model so the translatable + JSON casts encode `value`
     * exactly the way a normal save would.
     */
    private function attributesToPersist(): array
    {
        $productAttribute = new ProductsAttributes();
        $productAttribute->products_id = $this->product->getId();
        $productAttribute->attributes_id = $this->attribute->getId();
        $productAttribute->value = $this->value;

        return $productAttribute->getAttributes();
    }
}
