<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\DataTransferObject;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * What a parser (CSV mapper, PDF extraction, webhook JSON) hands the ingest action. Header fields are
 * optional because CSV exports rarely carry them; the balance validator derives what it can from the
 * running balance and refuses the statement when it cannot be checked at all.
 */
class ParsedBankStatement extends Data
{
    /**
     * @param list<BankStatementLine> $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly ?string $accountNumber = null,
        public readonly ?string $currency = null,
        public readonly ?Carbon $periodStart = null,
        public readonly ?Carbon $periodEnd = null,
        public readonly ?float $openingBalance = null,
        public readonly ?float $closingBalance = null,
    ) {
    }

    public function accountLast4(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->accountNumber);

        return $digits === '' ? null : substr($digits, -4);
    }

    public function withLines(array $lines, ?float $openingBalance, ?float $closingBalance): self
    {
        return new self(
            lines: $lines,
            accountNumber: $this->accountNumber,
            currency: $this->currency,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            openingBalance: $openingBalance,
            closingBalance: $closingBalance,
        );
    }
}
