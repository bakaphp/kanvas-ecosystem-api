<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

enum AccountTypeEnum: string
{
    case ASSET = 'asset';
    case LIABILITY = 'liability';
    case EQUITY = 'equity';
    case REVENUE = 'revenue';
    case EXPENSE = 'expense';
    case COGS = 'cogs';
    case OTHER_INCOME = 'other_income';
    case OTHER_EXPENSE = 'other_expense';

    /**
     * Debit-normal accounts increase on the debit side; credit-normal on the credit side.
     * Used by reports + JE composers to decide which side of the entry an account belongs on.
     */
    public function isDebitNormal(): bool
    {
        return match ($this) {
            self::ASSET, self::EXPENSE, self::COGS, self::OTHER_EXPENSE => true,
            self::LIABILITY, self::EQUITY, self::REVENUE, self::OTHER_INCOME => false,
        };
    }
}
