<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Services;

use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Gates expense status transitions.
 *
 *   DRAFT             → PENDING_APPROVAL | VOIDED
 *   PENDING_APPROVAL  → APPROVED         | REJECTED
 *   APPROVED          → VOIDED                            (posts reversal JE)
 *   REJECTED          → (terminal)
 *   VOIDED            → (terminal)
 */
class ExpenseStateMachine
{
    /**
     * @var array<string, list<ExpenseStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            ExpenseStatusEnum::PENDING_APPROVAL,
            ExpenseStatusEnum::VOIDED,
        ],
        'pending_approval' => [
            ExpenseStatusEnum::APPROVED,
            ExpenseStatusEnum::REJECTED,
        ],
        'approved' => [
            ExpenseStatusEnum::VOIDED,
        ],
        'rejected' => [],
        'voided' => [],
    ];

    public function assertTransition(Expense $expense, ExpenseStatusEnum $target): void
    {
        $current = $expense->status;

        if (! $this->canTransition($current, $target)) {
            throw new InvalidExpenseTransitionException(
                "Expense {$expense->id} cannot transition status "
                . "from '{$current->value}' to '{$target->value}'. "
                . 'Allowed targets: ' . $this->formatAllowed($current) . '.'
            );
        }
    }

    public function canTransition(ExpenseStatusEnum $from, ExpenseStatusEnum $to): bool
    {
        if ($from === $to) {
            return true;
        }

        $allowed = self::ALLOWED[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    private function formatAllowed(ExpenseStatusEnum $from): string
    {
        $allowed = self::ALLOWED[$from->value] ?? [];
        if ($allowed === []) {
            return '(none — terminal state)';
        }

        return implode(', ', array_map(fn (ExpenseStatusEnum $e) => $e->value, $allowed));
    }
}
