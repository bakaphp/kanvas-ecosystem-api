<?php

declare(strict_types=1);

namespace Kanvas\Scribe\TaxCodes\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

class TaxRateData extends Data
{
    public function __construct(
        public readonly string $name,
        public readonly float $rate,
        public readonly Carbon $effective_from,
        public readonly ?int $tax_account_id = null,
        public readonly ?Carbon $effective_to = null,
        public readonly int $sort_order = 0,
        public readonly ?array $metadata = null,
    ) {
    }
}
