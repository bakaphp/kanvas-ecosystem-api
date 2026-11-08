<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

/**
 * Why a statement landed nothing. Each reason is written for the agent, which relays it to the person
 * who sent the file and asks for a corrected one.
 */
enum BankStatementRejectionEnum: string
{
    case NO_ROWS = 'no_rows';
    case NO_BALANCES = 'no_balances';
    case BALANCE_MISMATCH = 'balance_mismatch';
    case RUNNING_BALANCE_BREAK = 'running_balance_break';
    case ACCOUNT_MISMATCH = 'account_mismatch';
    case CURRENCY_MISMATCH = 'currency_mismatch';
    case ACCOUNT_NOT_FOUND = 'account_not_found';
    case UNREADABLE_ROW = 'unreadable_row';

    public function guidance(): string
    {
        return match ($this) {
            self::NO_ROWS => 'The file has no movements. Ask for the statement export that includes the transaction rows.',
            self::NO_BALANCES => 'The statement shows neither opening/closing balances nor a running balance, so it cannot '
                . 'be checked. Ask for an export that includes the balance column.',
            self::BALANCE_MISMATCH, self::RUNNING_BALANCE_BREAK => 'The movements do not add up to the balances on the '
                . 'statement, which usually means a misread or a partial file. Ask for a clean file — a CSV export '
                . 'rather than a scanned PDF when possible.',
            self::ACCOUNT_MISMATCH, self::CURRENCY_MISMATCH => 'The statement belongs to a different account than the '
                . 'one it was sent for. Confirm which account it is before trying again.',
            self::ACCOUNT_NOT_FOUND => 'No bank account on file matches the statement\'s account number and currency. '
                . 'Ask which account it is, or have the account set up first.',
            self::UNREADABLE_ROW => 'A row has no readable date or amount. Ask for a clean export.',
        };
    }
}
