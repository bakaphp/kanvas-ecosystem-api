<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\DataTransferObject;

use Spatie\LaravelData\Data;

class BillTaxLineData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly float $tax_amount_native,
        public readonly ?int $tax_code_id = null,
        public readonly ?float $tax_rate = null,
        public readonly ?string $jurisdiction = null,
        public readonly ?array $metadata = null,
    ) {
    }
}
