<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Reports\DataTransferObject;

use Spatie\LaravelData\Data;

class TrialBalanceRow extends Data
{
    public function __construct(
        public readonly int $account_id,
        public readonly string $account_number,
        public readonly string $name,
        public readonly string $account_type,
        public readonly ?string $account_sub_type,
        public readonly float $debit,
        public readonly float $credit,
    ) {
    }
}
