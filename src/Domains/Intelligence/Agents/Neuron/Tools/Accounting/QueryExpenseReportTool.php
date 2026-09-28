<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Reports\Repositories\ExpenseSummaryRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Query Expense Report', category: 'accounting')]
class QueryExpenseReportTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'query_expense_report',
            description: 'Summarises approved expenses over a period — the total, broken down by expense '
                . 'category, by the employee who paid, and by payment method, plus the employee-paid vs '
                . 'company-paid split. Use it for "what did we spend on travel last month", "expense report for '
                . 'September", or "how much of our spending is staff paying out of pocket". Counts APPROVED '
                . 'expenses only, since that is when the cost hits the books.',
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
                name: 'period_start',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD). Defaults to the first day of the current month.',
                required: false,
            ),
            new ToolProperty(
                name: 'period_end',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD). Defaults to the last day of the period_start month.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Reporting currency. Defaults to USD.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $period_start = null,
        ?string $period_end = null,
        ?string $currency = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('expense report');
        }

        $start = $period_start !== null
            ? Carbon::parse($period_start)->startOfDay()
            : Carbon::today()->startOfMonth();
        $end = $period_end !== null
            ? Carbon::parse($period_end)->endOfDay()
            : $start->copy()->endOfMonth();

        $data = new ExpenseSummaryRepository()->generate(
            app: $this->app,
            company: $this->company,
            periodStart: $start,
            periodEnd: $end,
            currency: $currency ?? 'USD',
        );

        if ($data->expense_count === 0) {
            return $this->noop(
                $data->toArray(),
                guidance: 'No approved expenses in that period. An expense filed but not yet approved does not '
                    . 'appear here, so try a wider period before saying there was no spending.',
            );
        }

        return $this->ok($data->toArray());
    }
}
