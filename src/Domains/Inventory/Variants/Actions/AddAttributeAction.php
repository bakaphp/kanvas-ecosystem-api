<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;
use Kanvas\Inventory\Variants\Models\Variants;

class AddAttributeAction
{
    public function __construct(
        public Variants $variant,
        public Attributes $attribute,
        public mixed $value,
    ) {
    }

    /**
     * execute.
     */
    public function execute(): Variants
    {
        if ($this->value === null || $this->value === '') {
            return $this->variant;
        }

        if ($this->variant->attributes()->find($this->attribute->getId())) {
            $this->variant->attributes()->syncWithoutDetaching(
                [$this->attribute->getId() => ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]]
            );
        } else {
            $this->variant->attributes()->attach(
                $this->attribute->getId(),
                ['value' => is_array($this->value) ? json_encode($this->value) : $this->value]
            );
        }

        if ($this->variant->product->productsType) {
            ProductTypeService::addAttributes(
                $this->variant->product->productsType,
                $this->variant->product->user,
                [
                    [
                        'id' => $this->attribute->getId(),
                        'value' => $this->value,
                    ],
                ]
            );
        }

        return $this->variant;
    }
}
