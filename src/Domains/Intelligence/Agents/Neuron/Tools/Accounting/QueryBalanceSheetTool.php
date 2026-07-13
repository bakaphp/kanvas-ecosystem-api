<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\BalanceSheetRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Balance Sheet')]
class QueryBalanceSheetTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_balance_sheet',
            description: 'Returns the company Balance Sheet as of a specific date — Assets / Liabilities / Equity '
                . 'with per-account rollup + the is_balanced invariant. Use this when the user asks about company '
                . 'net worth, what we own / owe, equity position, or wants a snapshot of the books.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'as_of',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) to compute the balance sheet as of. Defaults to today.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Reporting currency (e.g. USD, DOP). Defaults to USD.',
                required: false,
            ),
        ];
    }

    public function __invoke(?string $as_of = null, ?string $currency = null): array
    {
        $app = $this->app;
        $user = $this->user;
        $company = $user->getCurrentCompany();

        $data = new BalanceSheetRepository()->generate(
            app: $app,
            company: $company,
            asOf: $as_of !== null ? Carbon::parse($as_of) : Carbon::today(),
            currency: $currency ?? 'USD',
        );

        return $data->toArray();
    }
}
