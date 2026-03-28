<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\DataTransferObject;

use Spatie\LaravelData\Data;

class CommissionStats extends Data
{
    public function __construct(
        public readonly float $total_revenue,
        public readonly float $total_commission,
        public readonly float $total_provider_amount,
        public readonly int $order_count,
    ) {
    }
}
