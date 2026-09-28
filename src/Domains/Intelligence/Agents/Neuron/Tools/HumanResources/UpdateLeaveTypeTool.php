<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Leave\Actions\UpdateLeaveTypeAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
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
 * Changes a leave policy's rules. Admin only.
 *
 * Editing a policy is forward-looking: it changes what NEW assignments are seeded with, and leaves
 * every balance already granted alone. Raising everyone's vacation for the current year is
 * set_employee_leave_balance per employee, not this.
 */
#[AgentTool(name: 'Update Leave Type', category: 'human_resources')]
class UpdateLeaveTypeTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'update_leave_type',
            description: 'Changes an existing leave type (leave policy): its annual entitlement, carryover cap, paid '
                . 'flag, accrual method, approval rule, name, or whether it is still active. Admin only. Only the '
                . 'fields you pass change. This affects FUTURE assignments only — balances already granted keep their '
                . 'days, so use set_employee_leave_balance to change what someone already has.',
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
                description: 'The current name of the policy to change. Use list_leave_types to get it exactly.',
                required: true,
            ),
            new ToolProperty(
                name: 'new_name',
                type: PropertyType::STRING,
                description: 'Rename the policy to this.',
                required: false,
            ),
            new ToolProperty(
                name: 'default_annual_days',
                type: PropertyType::NUMBER,
                description: 'New days granted per year for future assignments.',
                required: false,
            ),
            new ToolProperty(
                name: 'is_paid',
                type: PropertyType::BOOLEAN,
                description: 'Whether the time off is paid.',
                required: false,
            ),
            new ToolProperty(
                name: 'accrual_method',
                type: PropertyType::STRING,
                description: 'One of: annual_allotment, monthly_accrual, unlimited.',
                required: false,
            ),
            new ToolProperty(
                name: 'carryover_max_days',
                type: PropertyType::NUMBER,
                description: 'Maximum days that may roll into next year.',
                required: false,
            ),
            new ToolProperty(
                name: 'requires_approval',
                type: PropertyType::BOOLEAN,
                description: 'Whether a manager must approve requests of this type.',
                required: false,
            ),
            new ToolProperty(
                name: 'is_active',
                type: PropertyType::BOOLEAN,
                description: 'Set false to retire the policy so it can no longer be requested against.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $name,
        ?string $new_name = null,
        int|float|null $default_annual_days = null,
        ?bool $is_paid = null,
        ?string $accrual_method = null,
        int|float|null $carryover_max_days = null,
        ?bool $requires_approval = null,
        ?bool $is_active = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $type = $this->resolveLeaveTypeOrError($name);

        if (! $type instanceof LeaveType) {
            return ['updated' => false, 'message' => $type['message']];
        }

        $accrual = $accrual_method === null
            ? AccrualMethodEnum::from($type->accrual_method)
            : $this->resolveAccrualMethod($accrual_method);

        if (is_array($accrual)) {
            return ['updated' => false, 'message' => $accrual['message']];
        }

        $updated = new UpdateLeaveTypeAction(
            $type,
            new LeaveTypeData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                name: $new_name ?? $type->name,
                isPaid: $is_paid ?? $type->is_paid,
                accrualMethod: $accrual,
                defaultAnnualDays: $this->daysOrNull($default_annual_days) ?? $type->default_annual_days,
                carryoverMaxDays: $this->daysOrNull($carryover_max_days) ?? $type->carryover_max_days,
                requiresApproval: $requires_approval ?? $type->requires_approval,
                color: $type->color,
                isActive: $is_active ?? $type->is_active,
            ),
        )->execute();

        return [
            'updated' => true,
            'leave_type_id' => $updated->getId(),
            'name' => $updated->name,
            'default_annual_days' => $updated->default_annual_days,
            'carryover_max_days' => $updated->carryover_max_days,
            'accrual_method' => $updated->accrual_method,
            'is_paid' => $updated->is_paid,
            'requires_approval' => $updated->requires_approval,
            'is_active' => $updated->is_active,
            'message' => 'Policy updated. Existing balances are unchanged — use set_employee_leave_balance for those.',
        ];
    }
}
