<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;

class AddAttributeAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private Products $product,
        private Attributes $attribute,
        private int|string $value
    ) {
    }

    /**
     * execute.
     *
     * @return Products
     */
    public function execute(): Products
    {
        if ($this->product->attributes()->find($this->attribute->getId())) {
            $this->product->attributes()->syncWithoutDetaching([$this->attribute->getId() => ['value' => $this->value]]);
        } else {
            $this->product->attributes()->attach($this->attribute->getId(), ['value' => $this->value]);
        }

        return $this->product;
    }
}
