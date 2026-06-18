<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * @property DataCollection<TrialBalanceRow> $rows
 */
class TrialBalanceData extends Data
{
    public function __construct(
        public readonly Carbon $as_of,
        public readonly string $currency,
        /** @var DataCollection<TrialBalanceRow> */
        public readonly DataCollection $rows,
        public readonly float $total_debits,
        public readonly float $total_credits,
        public readonly bool $is_balanced,
    ) {
    }
}
