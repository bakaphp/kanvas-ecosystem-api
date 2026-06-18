<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

use Spatie\LaravelData\Data;

class BillLine extends Data
{
    public function __construct(
        public readonly string $description,
        public readonly float $quantity,
        public readonly float $unit_price_native,
        public readonly ?int $item_id = null,
        public readonly ?string $sku = null,
        public readonly ?int $sort_order = null,
        public readonly ?float $discount_rate = null,
        public readonly float $discount_amount_native = 0.0,
        public readonly ?float $tax_rate = null,
        public readonly float $tax_amount_native = 0.0,
        public readonly ?int $expense_account_id = null,
        public readonly ?array $tax_metadata = null,
        public readonly ?int $class_id = null,
        public readonly ?int $department_id = null,
        public readonly ?array $metadata = null,
    ) {
    }

    public function lineSubtotalNative(): float
    {
        return $this->quantity * $this->unit_price_native;
    }

    public function lineTotalNative(): float
    {
        return $this->lineSubtotalNative() - $this->discount_amount_native + $this->tax_amount_native;
    }
}
