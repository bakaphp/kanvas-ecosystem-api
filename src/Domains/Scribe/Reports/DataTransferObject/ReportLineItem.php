<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

/**
 * One row in a report — an account-level rollup. Used by BalanceSheet / P&L / TrialBalance sections.
 */
class ReportLineItem extends Data
{
    public function __construct(
        public readonly int $account_id,
        public readonly string $account_number,
        public readonly string $name,
        public readonly string $account_type,
        public readonly ?string $account_sub_type,
        public readonly float $amount,
    ) {
    }
}
