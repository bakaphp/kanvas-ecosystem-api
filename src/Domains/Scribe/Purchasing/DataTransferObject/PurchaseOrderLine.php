<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\DataTransferObject;

use Spatie\LaravelData\Data;

class PurchaseOrderLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unit_cost,
        public readonly ?string $sku = null,
        public readonly ?int $inventory_variant_id = null,
        public readonly ?int $expense_account_id = null,
        public readonly ?int $subaccount_id = null,
    ) {
    }

    public function extendedCost(): float
    {
        return round($this->quantity * $this->unit_cost, 4);
    }
}
