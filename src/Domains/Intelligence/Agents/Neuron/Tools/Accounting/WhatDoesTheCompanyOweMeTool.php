<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\Reports\Repositories\DueToEmployeesRepository;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Self-service: what the company owes the CALLER for expenses they paid out of pocket. The employee
 * is resolved from the requesting user, so it takes no employee identifier and can only ever read
 * the caller's own position — query_due_to_employees is the everyone-else view, for finance.
 */
#[AgentTool(name: 'What Does The Company Owe Me', category: 'accounting')]
class WhatDoesTheCompanyOweMeTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'what_does_the_company_owe_me',
            description: 'Returns what the company still owes YOU for expenses you paid out of pocket and have not '
                . 'been reimbursed for yet — the total, this month\'s share, a per-month breakdown, and how long the '
                . 'oldest one has been waiting. Use this for "how much do I get reimbursed", "what does the company '
                . 'owe me this month", or "am I still owed for that dinner". It always reads the position of the '
                . 'person you are talking to — it takes no employee identifier.',
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
                description: 'ISO date (YYYY-MM-DD) — defaults to today. "This month" is measured against it.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $as_of = null): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('reimbursement lookup');
        }

        $user = $this->contextUser();

        if ($user === null) {
            return $this->denied(
                'I cannot tell who you are on this surface, so I cannot look up your reimbursements.',
                ['status' => 'no_user_context'],
            );
        }

        $asOf = $as_of !== null ? Carbon::parse($as_of) : Carbon::today();

        $data = new DueToEmployeesRepository()->generate(
            app: $this->app,
            company: $this->company,
            asOf: $asOf,
            usersId: $user->getId(),
        );

        $row = $data->rows->toCollection()->first();

        // NOOP, not an error: a correct zero. Left unlabelled, a model reads it as a failed call and
        // retries with the same arguments until the run budget kills the turn.
        if ($row === null) {
            return $this->noop(
                [
                    'status' => 'nothing_owed',
                    'as_of' => $asOf->toDateString(),
                    'currency' => $data->currency,
                    'total_owed' => 0.0,
                    'expense_count' => 0,
                ],
                guidance: 'Tell them nothing is currently owed to them. An expense only counts here once a '
                    . 'manager has approved it, so one submitted today may simply still be pending.',
            );
        }

        return $this->ok([
            'status' => 'owed',
            'as_of' => $asOf->toDateString(),
            'currency' => $data->currency,
            'total_owed' => $row->total,
            'expense_count' => $row->expense_count,
            'current_month_total' => $row->current_month_total,
            'oldest_expense_date' => $row->oldest_expense_date->toDateString(),
            'days_outstanding' => $row->days_outstanding,
            'by_month' => $row->months->toArray(),
        ]);
    }
}
