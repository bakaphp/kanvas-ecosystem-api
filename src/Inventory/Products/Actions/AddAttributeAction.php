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
        private string $value
    ) {
    }

    /**
     * execute.
     *
     * @return void
     */
    public function execute() : Products
    {
        $this->product->attributes()->attach(
            $this->attribute->getId(),
            [
                'value' => $this->value
            ]
        );

        return $this->product;
    }
}
