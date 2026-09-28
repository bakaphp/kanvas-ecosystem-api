<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Models\Expense;
use Override;

/**
 * Approving an expense posts its journal entry and nothing else — there is no ERP push, so unlike the
 * bill and invoice handlers this one belongs in the domain rather than in a connector.
 */
class ApproveExpenseHandler implements ApprovalHandlerInterface
{
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        /** @var Expense|null $expense */
        $expense = $request->resolveEntity();

        if ($expense === null) {
            throw new ValidationException("Expense {$request->entity_id} no longer exists.");
        }

        if ($approver === null) {
            throw new ValidationException('Approving an expense requires an approving user.');
        }

        $expense = new ApproveExpenseAction($expense, $approver, $expense->vendor)->execute();

        return [
            'target_type' => 'expense',
            'target_id' => $expense->getId(),
            'label' => $expense->expense_number ?? (string) $expense->getId(),
            'pushed' => false,
            'reference' => null,
            'push_error' => null,
        ];
    }
}
