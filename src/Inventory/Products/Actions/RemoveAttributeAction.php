<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Attributes\Models\Attributes;

class RemoveAttributeAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        private Products $product,
        private Attributes $attribute
    ) {
    }

    /**
     * execute
     *
     * @return void
     */
    public function execute(): Products
    {
        $this->product->attributes()->detach($this->attribute->id);
        return $this->product;
    }
}
