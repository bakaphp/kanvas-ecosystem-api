<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;

class RemoveAttributeAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        private Products $product,
        private Attributes $attribute
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Products
    {
        $this->product->attributes()->detach($this->attribute->getId());

        return $this->product;
    }
}
