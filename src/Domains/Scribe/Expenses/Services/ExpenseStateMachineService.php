<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Services;

use Closure;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Override;

/**
 * Gates expense status transitions.
 *
 *   DRAFT             → PENDING_APPROVAL | VOIDED
 *   PENDING_APPROVAL  → APPROVED         | REJECTED
 *   APPROVED          → VOIDED                            (posts reversal JE)
 *   REJECTED          → (terminal)
 *   VOIDED            → (terminal)
 */
class ExpenseStateMachineService extends AbstractDocumentStateMachineService
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

    /**
     * @throws InvalidExpenseTransitionException
     */
    public function assertTransition(Expense $expense, ExpenseStatusEnum $target): void
    {
        $this->guardTransition(
            entityId: (int) $expense->id,
            current: $expense->status,
            target: $target,
        );
    }

    #[Override]
    protected function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Expense';
    }

    #[Override]
    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidExpenseTransitionException($message);
    }
}
