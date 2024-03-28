<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\DataTransferObject;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Spatie\LaravelData\Data;

class ProductsTypesAttributes extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public ProductsTypes $productsTypes,
        public Attributes $attributes,
        public bool $toVariant = false,
    ) {
    }

    /**
     * fromArray.
     *
     * @param  array $data
     *
     * @return ProductsTypesAttributes
     */
    public static function viaRequest(array $request): self
    {
        return new self(
            $request['product_type'],
            $request['attribute'],
            $request['toVariant'] ?? false,
        );
    }
}
