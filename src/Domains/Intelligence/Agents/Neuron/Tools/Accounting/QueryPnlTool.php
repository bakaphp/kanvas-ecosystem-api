<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Profit and Loss')]
class QueryPnlTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_pnl',
            description: 'Returns the Profit & Loss statement for a date range — Revenue, COGS, Gross Profit, '
                . 'Operating Expenses, Operating Income, Other Income/Expenses, Net Income. Use this when the user '
                . 'asks about company performance, profitability, revenue / expenses for a period (MTD, QTD, YTD).',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'period_start',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) — first day of the period.',
                required: true,
            ),
            new ToolProperty(
                name: 'period_end',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) — last day of the period.',
                required: true,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Reporting currency (e.g. USD, DOP). Defaults to USD.',
                required: false,
            ),
        ];
    }

    public function __invoke(string $period_start, string $period_end, ?string $currency = null): array
    {
        $app = $this->app;
        $user = $this->user;
        $company = $user->getCurrentCompany();

        $data = new ProfitAndLossRepository()->generate(
            app: $app,
            company: $company,
            periodStart: Carbon::parse($period_start),
            periodEnd: Carbon::parse($period_end),
            currency: $currency ?? 'USD',
        );

        return $data->toArray();
    }
}
