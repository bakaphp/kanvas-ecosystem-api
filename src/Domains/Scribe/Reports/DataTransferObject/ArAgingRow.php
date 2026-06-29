<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class ArAgingRow extends Data
{
    public function __construct(
        public readonly ?string $customer_type,
        public readonly ?int $customer_id,
        public readonly ?string $customer_name,
        public readonly float $current,
        public readonly float $bucket_1_30,
        public readonly float $bucket_31_60,
        public readonly float $bucket_61_90,
        public readonly float $bucket_90_plus,
        public readonly float $total,
    ) {
    }
}
