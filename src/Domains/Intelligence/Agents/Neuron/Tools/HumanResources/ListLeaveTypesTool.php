<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The leave TYPE is the policy in this domain — its annual days, carryover cap and approval rule are
 * what an employee's entitlement is seeded from. Without this the agent has to guess policy names
 * before every write, and a guessed name is the most common way the leave tools fail.
 */
#[AgentTool(name: 'List Leave Types', category: 'human_resources')]
class ListLeaveTypesTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_leave_types',
            description: 'Lists the company\'s leave types (leave policies) with their annual entitlement, carryover '
                . 'cap, paid flag and accrual method. Call this before assign_leave_policy, set_employee_leave_balance '
                . 'or request_leave so you use a real policy name instead of guessing one.',
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
                name: 'include_inactive',
                type: PropertyType::BOOLEAN,
                description: 'Include retired/inactive leave types. Defaults to false.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?bool $include_inactive = null): array
    {
        $types = LeaveType::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($include_inactive !== true, fn (Builder $query): Builder => $query->where('is_active', 1))
            ->orderBy('name')
            ->get()
            ->map(fn (LeaveType $type): array => [
                'leave_type_id' => $type->getId(),
                'name' => $type->name,
                'is_paid' => $type->is_paid,
                'accrual_method' => $type->accrual_method,
                'default_annual_days' => $type->default_annual_days,
                'carryover_max_days' => $type->carryover_max_days,
                'requires_approval' => $type->requires_approval,
                'is_active' => $type->is_active,
            ])->all();

        return [
            'count' => count($types),
            'leave_types' => $types,
            'note' => $types === []
                ? 'This company has no leave types yet — create one with create_leave_type before assigning leave.'
                : 'Use these names verbatim in the other leave tools.',
        ];
    }
}
