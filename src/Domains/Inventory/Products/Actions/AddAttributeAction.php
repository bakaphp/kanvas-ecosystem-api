<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Products\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;

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

        if ($this->product->attributes()->find($this->attribute->getId())) {
            $this->product->attributes()->syncWithoutDetaching([$this->attribute->getId() => ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]]);
        } else {
            $this->product->attributes()->attach($this->attribute->getId(), ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]);
        }

        if ($this->product?->productsType) {
            ProductTypeService::addAttributes(
                $this->product->productsType,
                $this->product->user,
                [
                    [
                        'id' => $this->attribute->getId(),
                        'value' => $this->value,
                    ],
                ]
            );
        }

        return $this->product;
    }
}
