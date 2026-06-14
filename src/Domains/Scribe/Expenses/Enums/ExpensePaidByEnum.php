<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Enums;

use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;

/**
 * Who paid the expense — drives the JE shape per plan §11.4.
 *
 *   COMPANY_CARD          → CR Credit Card Liability   (card swipe; balance settled later)
 *   COMPANY_CASH          → CR Cash on Hand            (petty cash withdrawal)
 *   EMPLOYEE_PERSONAL     → CR Due to Employees        (employee paid out of pocket; reimbursement owed)
 *   COMPANY_BANK_TRANSFER → CR Cash (bank account)     (direct ACH/wire from company account)
 *
 * Each case knows which AccountSubTypeEnum to credit; the JE composer routes accordingly.
 */
enum ExpensePaidByEnum: string
{
    case COMPANY_CARD = 'company_card';
    case COMPANY_CASH = 'company_cash';
    case EMPLOYEE_PERSONAL = 'employee_personal';
    case COMPANY_BANK_TRANSFER = 'company_bank_transfer';

    /**
     * Which system account sub_type to CREDIT on the approval JE. Reimbursement (for EMPLOYEE_PERSONAL)
     * has its own JE — see ExpenseJournalEntryComposer::composeReimbursement.
     */
    public function creditAccountSubType(): AccountSubTypeEnum
    {
        return match ($this) {
            self::COMPANY_CARD => AccountSubTypeEnum::CREDIT_CARD_LIABILITY,
            self::COMPANY_CASH => AccountSubTypeEnum::CASH_ON_HAND,
            self::EMPLOYEE_PERSONAL => AccountSubTypeEnum::DUE_TO_EMPLOYEES,
            self::COMPANY_BANK_TRANSFER => AccountSubTypeEnum::CASH_CHECKING,
        };
    }

    /**
     * Employee-paid expenses generate a separate reimbursement obligation (Due to Employees liability).
     * Other paid-by types don't — the money already left the company.
     */
    public function requiresReimbursement(): bool
    {
        return $this === self::EMPLOYEE_PERSONAL;
    }
}
