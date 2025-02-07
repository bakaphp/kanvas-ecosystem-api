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
        //Avoid to use sync method on models that can be translatable even pivot tables.
        $productAttribute = ProductsAttributes::where('products_id', $this->product->getId())
                            ->where('attributes_id', $this->attribute->getId())
                            ->first();

        if ($productAttribute) {
            $productAttribute->value = $this->value;
            $productAttribute->update();
        } else {
            $productAttribute = new ProductsAttributes();
            $productAttribute->products_id = $this->product->getId();
            $productAttribute->attributes_id = $this->attribute->getId();
            $productAttribute->value = is_array($this->value) ? json_encode($this->value) : $this->value;
            $productAttribute->save();
        }

        return $this->product;
    }
}
