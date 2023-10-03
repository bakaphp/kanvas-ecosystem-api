<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\DataTransferObject;

use Spatie\LaravelData\Data;

class VariantsWarehouses extends Data
{
    public function __construct(
        public ?float $quantity = null,
        public ?float $price = null,
        public ?string $sku = null,
        public int $position = 0,
        public ?string $serial_number = null,
        public ?int $status_id = null,
        public bool $is_oversellable = false,
        public bool $is_default = false,
        public bool $is_best_seller = false,
        public bool $is_on_sale = false,
        public bool $is_on_promo = false,
        public bool $can_pre_order = false,
        public bool $is_coming_son = false,
        public bool $is_new = false,
    ) {
    }

    public static function viaRequest(array $request): self
    {
        return new self(
            $request['quantity'] ?? null,
            $request['price'] ?? null,
            $request['sku'] ?? null,
            $request['position'] ?? 0,
            $request['serial_number'] ?? null,
            $request['status_id'] ?? null,
            $request['is_oversellable'] ?? false,
            $request['is_default'] ?? false,
            $request['is_best_seller'] ?? false,
            $request['is_on_sale'] ?? false,
            $request['is_on_promo'] ?? false,
            $request['can_pre_order'] ?? false,
            $request['is_coming_son'] ?? false,
            $request['is_new'] ?? false,
        );
    }
}
