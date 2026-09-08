<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\RequiresHumanCaller;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Self-service: the caller's own expenses, in any state. Answers "did that dinner ever get approved"
 * — which what_does_the_company_owe_me cannot, because that one only counts approved-and-unpaid.
 */
#[AgentTool(name: 'List My Expenses', category: 'accounting')]
class ListMyExpensesTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use RequiresHumanCaller;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_my_expenses',
            description: 'Lists expenses YOU filed, in any state — draft, waiting on approval, approved, rejected '
                . 'or already reimbursed. Use it for "what did I submit this month", "did my dinner expense get '
                . 'approved", or "was that taxi ever paid back". It always reads the expenses of the person you '
                . 'are talking to and takes no employee identifier.',
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
                name: 'status',
                type: PropertyType::STRING,
                description: 'Optional filter — one of: draft, pending_approval, approved, rejected, voided.',
                required: false,
            ),
            new ToolProperty(
                name: 'since',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD). Only expenses dated on or after it. Defaults to 90 days ago.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max rows to return. Defaults to 25, capped at 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $status = null, ?string $since = null, ?int $limit = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense list');
        }

        $user = $this->knownCallerOrDenial('expenses');

        if (is_array($user)) {
            return $user;
        }

        $statusFilter = ExpenseStatusEnum::tryFrom(trim((string) $status));
        $sinceDate = $since !== null ? Carbon::parse($since) : Carbon::today()->subDays(90);

        $query = Expense::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('paid_by', ExpensePaidByEnum::EMPLOYEE_PERSONAL->value)
            ->where('paid_by_users_id', $user->getId())
            ->whereDate('expense_date', '>=', $sinceDate)
            ->orderByDesc('expense_date')
            ->limit(max(1, min($limit ?? 25, 100)));

        if ($statusFilter !== null) {
            $query->where('status', $statusFilter->value);
        }

        $rows = $query->get()->map(fn (Expense $expense): array => [
            'expense_id' => $expense->getId(),
            'expense_number' => $expense->expense_number,
            'expense_date' => $expense->expense_date->toDateString(),
            'merchant' => $expense->vendor_display_name,
            'description' => $expense->notes,
            'total' => (float) $expense->total_native,
            'currency' => $expense->currency,
            'status' => $expense->status->value,
            'reimbursement_status' => $expense->reimbursement_status->value,
        ])->all();

        if ($rows === []) {
            return $this->noop(
                [
                    'status' => 'none_found',
                    'expenses' => [],
                    'since' => $sinceDate->toDateString(),
                ],
                guidance: 'They have filed nothing matching that. Widen the date range with `since` before '
                    . 'concluding there is nothing at all.',
            );
        }

        return $this->ok([
            'status' => 'found',
            'since' => $sinceDate->toDateString(),
            'count' => count($rows),
            'expenses' => $rows,
        ]);
    }
}
