<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Reports\Repositories\DueToEmployeesRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Query Due To Employees', category: 'accounting')]
class QueryDueToEmployeesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'query_due_to_employees',
            description: 'Returns what the company still owes its own staff for expenses employees paid out of '
                . 'pocket, grouped by employee and by month, with the oldest unreimbursed date per person. Use this '
                . 'for "what do we owe our team", "who is waiting on a reimbursement", or a per-employee '
                . 'reimbursement breakdown. This is NOT accounts payable — vendor debt is query_ap_aging.',
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

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $as_of = null, ?string $currency = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('employee reimbursement report');
        }

        $data = new DueToEmployeesRepository()->generate(
            app: $this->app,
            company: $this->company,
            asOf: $as_of !== null ? Carbon::parse($as_of) : Carbon::today(),
            currency: $currency ?? 'USD',
        );

        return $data->toArray();
    }
}
