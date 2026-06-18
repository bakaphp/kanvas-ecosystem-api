<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<ArAgingRow> $rows
 */
class ArAgingData extends Data
{
    public function __construct(
        public readonly Carbon $as_of,
        public readonly string $currency,
        /** @var DataCollection<ArAgingRow> */
        public readonly DataCollection $rows,
        public readonly float $total_current,
        public readonly float $total_1_30,
        public readonly float $total_31_60,
        public readonly float $total_61_90,
        public readonly float $total_90_plus,
        public readonly float $grand_total,
    ) {
    }
}
