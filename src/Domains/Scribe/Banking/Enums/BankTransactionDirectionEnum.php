<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

/**
 * Direction from the COMPANY's point of view, not the bank's ledger convention.
 *
 * CREDIT = money into our account (settles an Invoice / SalesReceipt).
 * DEBIT  = money out of our account (settles a Bill / Expense).
 *
 * Connectors normalize their own sign convention onto this — Mercury reports a signed amount, so its
 * DTO maps negative → DEBIT.
 */
enum BankTransactionDirectionEnum: string
{
    case DEBIT = 'debit';
    case CREDIT = 'credit';

    public function isMoneyIn(): bool
    {
        return $this === self::CREDIT;
    }

    public function isMoneyOut(): bool
    {
        return $this === self::DEBIT;
    }
}
