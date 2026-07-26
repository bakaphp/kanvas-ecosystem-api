<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\TrialBalanceRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Trial Balance')]
class QueryTrialBalanceTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_trial_balance',
            description: 'Returns the Trial Balance as of a date — every account with non-zero balance plus the '
                . 'grand-total DR=CR invariant. Use this to audit the books — if is_balanced is false, the GL is '
                . 'corrupt and posting is unsafe. Use this when the user asks "are the books balanced", wants an '
                . 'audit-style view, or is investigating a specific account.',
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

        $data = new TrialBalanceRepository()->generate(
            app: $app,
            company: $company,
            asOf: $as_of !== null ? Carbon::parse($as_of) : Carbon::today(),
            currency: $currency ?? 'USD',
        );

        return $data->toArray();
    }
}
