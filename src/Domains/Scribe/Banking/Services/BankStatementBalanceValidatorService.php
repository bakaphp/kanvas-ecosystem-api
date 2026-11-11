<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Kanvas\Scribe\Banking\DataTransferObject\BankStatementLine;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementRejectionEnum;
use Kanvas\Scribe\Banking\Exceptions\BankStatementRejectedException;

/**
 * The gate in front of every statement: opening + credits − debits must equal closing, and every
 * running balance must follow from the one before it. This is the main protection against a misread
 * PDF — a dropped row or a swapped digit breaks the arithmetic — so a statement that fails lands nothing.
 *
 * Banks export newest-first as often as oldest-first, so when the running balances only line up in
 * reverse the statement is accepted in that order. Returns the statement with chronological lines and
 * both balances filled in.
 */
class BankStatementBalanceValidatorService
{
    private const float TOLERANCE = 0.005;

    public function validate(ParsedBankStatement $statement): ParsedBankStatement
    {
        if ($statement->lines === []) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::NO_ROWS,
                'The statement has no movements.',
            );
        }

        $hasRunningBalances = array_filter(
            $statement->lines,
            fn (BankStatementLine $line): bool => $line->runningBalance !== null,
        ) !== [];

        $failure = null;

        foreach ($this->candidateOrders($statement->lines, $hasRunningBalances) as $lines) {
            $opening = $statement->openingBalance ?? $this->derivedOpening($lines[0]);
            $closing = $statement->closingBalance ?? $lines[array_key_last($lines)]->runningBalance;

            if ($opening === null || $closing === null) {
                throw new BankStatementRejectedException(
                    BankStatementRejectionEnum::NO_BALANCES,
                    'The statement has no opening/closing balance and no running balance to check it against.',
                );
            }

            $break = $this->runningBalanceBreak($lines, $opening);

            if ($break === null) {
                $this->assertSumsToClosing($lines, $opening, $closing);

                return $statement->withLines($lines, round($opening, 4), round($closing, 4));
            }

            // Report the break in the order the bank sent it; the reversed attempt is our guess.
            $failure ??= $break;
        }

        throw new BankStatementRejectedException(BankStatementRejectionEnum::RUNNING_BALANCE_BREAK, (string) $failure);
    }

    /**
     * @param list<BankStatementLine> $lines
     * @return list<list<BankStatementLine>>
     */
    private function candidateOrders(array $lines, bool $hasRunningBalances): array
    {
        if ($hasRunningBalances) {
            return [$lines, array_reverse($lines)];
        }

        // With nothing to chain, chronological is the only order that means anything. usort is stable,
        // so same-day rows keep the bank's order.
        usort($lines, fn (BankStatementLine $a, BankStatementLine $b): int => $a->date <=> $b->date);

        return [$lines];
    }

    private function derivedOpening(BankStatementLine $first): ?float
    {
        return $first->runningBalance === null ? null : $first->runningBalance - $first->amount;
    }

    /**
     * @param list<BankStatementLine> $lines
     */
    private function runningBalanceBreak(array $lines, float $opening): ?string
    {
        $balance = $opening;

        foreach ($lines as $index => $line) {
            $balance += $line->amount;

            if ($line->runningBalance === null) {
                continue;
            }

            if (abs($balance - $line->runningBalance) > self::TOLERANCE) {
                return sprintf(
                    'Row %d (%s, %s) shows a running balance of %s, but the previous balance plus this movement is %s.',
                    $index + 1,
                    $line->date->toDateString(),
                    number_format($line->amount, 2, '.', ''),
                    number_format($line->runningBalance, 2, '.', ''),
                    number_format($balance, 2, '.', ''),
                );
            }

            $balance = $line->runningBalance;
        }

        return null;
    }

    /**
     * @param list<BankStatementLine> $lines
     */
    private function assertSumsToClosing(array $lines, float $opening, float $closing): void
    {
        $credits = array_sum(array_map(fn (BankStatementLine $l): float => max($l->amount, 0.0), $lines));
        $debits = array_sum(array_map(fn (BankStatementLine $l): float => abs(min($l->amount, 0.0)), $lines));
        $computed = $opening + $credits - $debits;

        if (abs($computed - $closing) > self::TOLERANCE) {
            throw new BankStatementRejectedException(
                BankStatementRejectionEnum::BALANCE_MISMATCH,
                sprintf(
                    'Opening %s + credits %s − debits %s = %s, but the statement closes at %s (difference %s).',
                    number_format($opening, 2, '.', ''),
                    number_format($credits, 2, '.', ''),
                    number_format($debits, 2, '.', ''),
                    number_format($computed, 2, '.', ''),
                    number_format($closing, 2, '.', ''),
                    number_format($closing - $computed, 2, '.', ''),
                ),
            );
        }
    }
}
