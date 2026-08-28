<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Leave\Actions\CreateLeaveTypeAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesLeaveForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Defines a leave policy. Admin only. Idempotent: an existing name comes back untouched rather than
 * duplicated, so the agent can call this before assign_leave_policy whenever it is unsure.
 */
#[AgentTool(name: 'Create Leave Type', category: 'human_resources')]
class CreateLeaveTypeTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_leave_type',
            description: 'Creates a leave type (leave policy) for the company — its name, annual entitlement, '
                . 'carryover cap, paid flag and whether requests need approval. Admin only. Returns created=false '
                . 'with already_exists=true if the name is taken; use update_leave_type to change that one instead.',
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
                name: 'name',
                type: PropertyType::STRING,
                description: 'The policy name, e.g. "Vacation", "Sick Leave", "Maternity".',
                required: true,
            ),
            new ToolProperty(
                name: 'default_annual_days',
                type: PropertyType::NUMBER,
                description: 'Days granted per year. This is what an employee\'s entitlement is seeded from when the '
                    . 'policy is assigned. Leave empty for an unlimited or purely accrued policy.',
                required: false,
            ),
            new ToolProperty(
                name: 'is_paid',
                type: PropertyType::BOOLEAN,
                description: 'Whether the time off is paid. Defaults to true.',
                required: false,
            ),
            new ToolProperty(
                name: 'accrual_method',
                type: PropertyType::STRING,
                description: 'One of: annual_allotment (whole year granted up front, the default), monthly_accrual, '
                    . 'unlimited.',
                required: false,
            ),
            new ToolProperty(
                name: 'carryover_max_days',
                type: PropertyType::NUMBER,
                description: 'Maximum days that may roll into next year. Empty means no carryover.',
                required: false,
            ),
            new ToolProperty(
                name: 'requires_approval',
                type: PropertyType::BOOLEAN,
                description: 'Whether a manager must approve requests of this type. Defaults to true.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        int|float|null $default_annual_days = null,
        ?bool $is_paid = null,
        ?string $accrual_method = null,
        int|float|null $carryover_max_days = null,
        ?bool $requires_approval = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $existing = $this->resolveLeaveTypeOrError($name);

        if ($existing instanceof LeaveType) {
            return [
                'created' => false,
                'already_exists' => true,
                'leave_type_id' => $existing->getId(),
                'name' => $existing->name,
                'default_annual_days' => $existing->default_annual_days,
                'message' => 'A leave type with this name already exists — use it as-is, or change it with '
                    . 'update_leave_type.',
            ];
        }

        $accrual = $this->resolveAccrualMethod($accrual_method);

        if (is_array($accrual)) {
            return $accrual;
        }

        $type = new CreateLeaveTypeAction(
            new LeaveTypeData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                name: $name,
                isPaid: $is_paid ?? true,
                accrualMethod: $accrual,
                defaultAnnualDays: $this->daysOrNull($default_annual_days),
                carryoverMaxDays: $this->daysOrNull($carryover_max_days),
                requiresApproval: $requires_approval ?? true,
            ),
        )->execute();

        return [
            'created' => true,
            'leave_type_id' => $type->getId(),
            'name' => $type->name,
            'default_annual_days' => $type->default_annual_days,
            'accrual_method' => $type->accrual_method,
            'is_paid' => $type->is_paid,
            'message' => 'Policy created. It grants nobody anything yet — call assign_leave_policy per employee.',
        ];
    }
}
