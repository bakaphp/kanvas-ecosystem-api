<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Variants\Models\Variants;
use Spatie\LaravelData\Data;

class OrderItem extends Data
{
    public function __construct(
        public readonly Apps $app,
        public readonly Variants $variant,
        public readonly string $name,
        public readonly string $sku,
        public readonly int $quantity,
        public readonly float $price,
        public readonly float $tax,
        public readonly float $discount,
        public readonly Currencies $currency,
        public readonly int $quantityShipped = 0,
    ) {
    }
}
