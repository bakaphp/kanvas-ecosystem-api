<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class RevenueRow extends Data
{
    public function __construct(
        public readonly string $group_key,
        public readonly string $group_label,
        public readonly float $gross_revenue,
        public readonly float $discounts,
        public readonly float $net_revenue,
        public readonly int $invoice_count,
    ) {
    }
}
