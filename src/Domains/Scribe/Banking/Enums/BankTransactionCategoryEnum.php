<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;

/**
 * How the bank classified the movement. Only consulted when a txn stays UNMATCHED — a matched txn takes
 * its accounting from the document it settles.
 *
 * BANK_FEE / INTEREST_INCOME are recognized straight into their P&L account: we know what they are, so
 * parking them in Suspense would be noise. Everything else lands in Suspense until someone explains it.
 */
enum BankTransactionCategoryEnum: string
{
    case BANK_FEE = 'bank_fee';
    case INTEREST_INCOME = 'interest_income';
    case TRANSFER = 'transfer';
    case UNKNOWN = 'unknown';

    /**
     * The non-cash side of the standalone JE. Null means "we can't name it" → Suspense.
     */
    public function contraAccountSubType(): ?AccountSubTypeEnum
    {
        return match ($this) {
            self::BANK_FEE => AccountSubTypeEnum::BANK_FEES,
            self::INTEREST_INCOME => AccountSubTypeEnum::INTEREST_INCOME,
            self::TRANSFER, self::UNKNOWN => null,
        };
    }

    public function isRecognized(): bool
    {
        return $this->contraAccountSubType() !== null;
    }
}
