<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class VariantsWarehouses extends Data
{
    public function __construct(
        public float $quantity = 0.0,
        public float $price = 0.0,
        public ?string $sku = null,
        public int $position = 0,
        public ?string $serial_number = null,
        public bool $is_oversellable = false,
        public bool $is_default = false,
        public bool $is_best_seller = false,
        public bool $is_on_sale = false,
        public bool $is_on_promo = false,
        public bool $can_pre_order = false,
        public bool $is_coming_son = false,
        public bool $is_new = false,
        public bool $is_published = false,
    ) {
    }
}
