<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Recent Expenses')]
class QueryRecentExpensesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_recent_expenses',
            description: 'Lists recent expenses (any status — draft/pending/approved/rejected/voided) with their '
                . 'amount, paid_by, status, vendor, and expense_date. Use this when the user asks "what did we '
                . 'spend on lately", wants to investigate a recent purchase, or asks about pending approvals.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'days_back',
                type: PropertyType::INTEGER,
                description: 'How many days back to look. Defaults to 30, max 365.',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'Optional filter — one of: draft, pending_approval, approved, rejected, voided.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max records to return. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        ?int $days_back = null,
        ?string $status = null,
        ?int $limit = null,
    ): array {
        $app = $this->app;
        $user = $this->user;
        $company = $user->getCurrentCompany();
        $daysBack = max(1, min(365, $days_back ?? 30));
        $limit = max(1, min(100, $limit ?? 25));

        $query = Expense::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('is_deleted', false)
            ->whereDate('expense_date', '>=', Carbon::today()->subDays($daysBack));

        if ($status !== null && ExpenseStatusEnum::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $expenses = $query
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return [
            'period_start' => Carbon::today()->subDays($daysBack)->toDateString(),
            'period_end' => Carbon::today()->toDateString(),
            'count' => $expenses->count(),
            'expenses' => $expenses->map(fn (Expense $e): array => [
                'id' => (int) $e->id,
                'expense_number' => $e->expense_number,
                'status' => $e->status->value,
                'expense_date' => $e->expense_date->toDateString(),
                'vendor' => $e->vendor_display_name,
                'paid_by' => $e->paid_by->value,
                'paid_by_users_id' => $e->paid_by_users_id,
                'currency' => $e->currency,
                'total_native' => (float) $e->total_native,
                'total_base' => (float) $e->total_base,
                'reimbursement_status' => $e->reimbursement_status->value,
                'notes' => $e->notes,
            ])->all(),
        ];
    }
}
