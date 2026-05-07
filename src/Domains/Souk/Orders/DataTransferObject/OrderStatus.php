<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Kanvas\Souk\Orders\Models\OrderTypes;
use Spatie\LaravelData\Data;

class OrderStatus extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $slug = null,
        public readonly ?int $order_type_id = null,
        public readonly ?OrderTypes $orderType = null,
        public readonly ?int $sequence = null,
        public readonly ?bool $is_default = null,
        public readonly ?bool $is_final = null,
        public readonly ?array $transitions_from = null,
    ) {
    }

    public function getOrderTypeId(): ?int
    {
        if ($this->orderType) {
            return $this->orderType->getId();
        }

        return $this->order_type_id;
    }
}
