<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesExpenseForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\ExpenseLine;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Moves an expense onto the right expense account.
 *
 * Ingested and agent-filed expenses land on the Travel & Meals fallback because neither path tries to
 * classify — so without this, everything a tenant spends reports as travel. Draft-only on purpose:
 * once approved, the account is baked into a posted journal entry and changing it is a reclass entry,
 * not a field edit.
 */
#[AgentTool(name: 'Categorize Expense', category: 'accounting')]
class CategorizeExpenseTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesExpenseForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'categorize_expense',
            description: 'Books a DRAFT expense against a different expense account — "this is software, not '
                . 'travel". Pass the account by number or by name. It cannot change an expense that is already '
                . 'approved, because the account is part of a posted journal entry by then.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'expense_id',
                type: PropertyType::INTEGER,
                description: 'The expense to recategorize.',
                required: true,
            ),
            new ToolProperty(
                name: 'account',
                type: PropertyType::STRING,
                description: 'The expense account to book it against — its account number (e.g. "6200") or its '
                    . 'name (e.g. "Software Subscriptions"). Must be an expense-type account.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $expense_id, string $account): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense');
        }

        $result = $this->resolveExpenseOrError($expense_id);

        if (is_array($result)) {
            return $this->denied(
                (string) $result['message'],
                ['status' => 'not_found'],
                guidance: 'Say plainly that NOTHING was changed.',
            );
        }

        if ($result->status !== ExpenseStatusEnum::DRAFT) {
            return $this->denied(
                "Expense {$expense_id} is '{$result->status->value}', and only a draft can be recategorized.",
                ['status' => 'not_draft'],
                guidance: 'Say it was NOT recategorized. Once approved, Finance has to post a reclass entry.',
            );
        }

        $target = $this->resolveExpenseAccount(trim($account));

        if ($target === null) {
            return $this->invalidArgs(
                "No expense account matches '{$account}' in this company's chart of accounts.",
                ['status' => 'unknown_account'],
                guidance: 'Ask which account they mean rather than guessing a number.',
            );
        }

        try {
            ExpenseLine::query()
                ->where('expense_id', $result->getId())
                ->update(['expense_account_id' => $target->getId()]);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'I could not recategorize that expense: ' . $e->getMessage(),
                ['status' => 'not_updated'],
                guidance: 'Say plainly that NOTHING was changed.',
            );
        }

        return $this->ok([
            'status' => 'recategorized',
            'expense_id' => $result->getId(),
            'expense_number' => $result->expense_number,
            'account_id' => $target->getId(),
            'account_number' => $target->account_number,
            'account_name' => $target->name,
        ]);
    }

    private function resolveExpenseAccount(string $account): ?Account
    {
        return Account::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('is_deleted', false)
            ->where('account_type', AccountTypeEnum::EXPENSE->value)
            ->where(
                fn ($query) => $query
                    ->where('account_number', $account)
                    ->orWhere('name', $account)
            )
            ->first();
    }
}
