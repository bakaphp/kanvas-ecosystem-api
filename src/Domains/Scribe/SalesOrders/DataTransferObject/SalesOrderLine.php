<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\DataTransferObject;

use Spatie\LaravelData\Data;

class SalesOrderLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unit_price,
        public readonly ?string $sku = null,
    ) {
    }

    public function total(): float
    {
        return round($this->quantity * $this->unit_price, 4);
    }
}
