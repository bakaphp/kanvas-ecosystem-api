<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsAttributes;

class RemoveAttributeAction
{
    public function __construct(
        private Products $product,
        private Attributes $attribute
    ) {
    }

    /**
     * Deletes the pivot row directly rather than through the relation: Products::attributes() is a
     * HasMany (it joins `attributes` so the translated value resolves), and detach() only exists on
     * BelongsToMany — calling it threw BadMethodCallException, which made the removeAttribute
     * mutation unusable.
     */
    public function execute(): Products
    {
        ProductsAttributes::where('products_id', $this->product->getId())
            ->where('attributes_id', $this->attribute->getId())
            ->delete();

        $this->product->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);

        return $this->product;
    }
}
