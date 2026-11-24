<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Spatie\LaravelData\Data;

/**
 * One parsed statement row, whatever the format it came from. `amount` is signed from the company's side:
 * positive is money in, negative is money out.
 */
class BankStatementLine extends Data
{
    public function __construct(
        public readonly Carbon $date,
        public readonly float $amount,
        public readonly ?string $description = null,
        public readonly ?string $reference = null,
        public readonly ?float $runningBalance = null,
        public readonly ?string $counterparty = null,
        public readonly array $raw = [],
    ) {
    }

    public function direction(): BankTransactionDirectionEnum
    {
        return $this->amount >= 0
            ? BankTransactionDirectionEnum::CREDIT
            : BankTransactionDirectionEnum::DEBIT;
    }
}
