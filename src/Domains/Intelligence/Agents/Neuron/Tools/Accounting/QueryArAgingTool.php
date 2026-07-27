<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query AR Aging')]
class QueryArAgingTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_ar_aging',
            description: 'Returns the AR Aging report as of a date — outstanding invoices grouped by customer and '
                . 'bucketed by days overdue (current, 1-30, 31-60, 61-90, 90+). Use this when the user asks about '
                . 'who owes money, what AR exposure looks like, or wants a per-customer breakdown of receivables.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'as_of',
                type: PropertyType::STRING,
                description: 'ISO date (YYYY-MM-DD) — defaults to today.',
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

    public function __invoke(?string $as_of = null, ?string $currency = null): array
    {
        $app = $this->app;
        $company = $this->company;

        $data = new ArAgingRepository()->generate(
            app: $app,
            company: $company,
            asOf: $as_of !== null ? Carbon::parse($as_of) : Carbon::today(),
            currency: $currency ?? 'USD',
        );

        return $data->toArray();
    }
}
