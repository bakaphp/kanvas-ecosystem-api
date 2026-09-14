<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesLeaveForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesEmployeeForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Sets or nudges an employee's leave days directly. Admin only.
 *
 * used_days and pending_days are deliberately NOT writable — those are owned by the request/approve
 * flow, and letting the agent set them would silently desynchronise the outstanding requests from the
 * balance they were checked against.
 */
#[AgentTool(name: 'Set Employee Leave Balance', category: 'human_resources')]
class SetEmployeeLeaveBalanceTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'set_employee_leave_balance',
            description: 'Sets or adjusts an employee\'s leave days for one leave type and year. Admin only. Use '
                . 'entitled_days to set the granted total outright, or adjust_days to add (positive) or remove '
                . '(negative) days from it. Creates the balance if the employee did not have that policy yet. It '
                . 'cannot change used or pending days — those come from approved and outstanding requests. Returns '
                . 'updated=false with a reason if you are not an admin or the new total is below what is already '
                . 'used or pending.',
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
                name: 'leave_type',
                type: PropertyType::STRING,
                description: 'The leave policy name, e.g. "Vacation". Get it from list_leave_types.',
                required: true,
            ),
            new ToolProperty(
                name: 'employee_email',
                type: PropertyType::STRING,
                description: 'The employee\'s login email. Provide this or employee_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'employee_id',
                type: PropertyType::INTEGER,
                description: 'The employee id (from find_employee). Provide this or employee_email.',
                required: false,
            ),
            new ToolProperty(
                name: 'year',
                type: PropertyType::INTEGER,
                description: 'The calendar year to change. Defaults to the current year.',
                required: false,
            ),
            new ToolProperty(
                name: 'entitled_days',
                type: PropertyType::NUMBER,
                description: 'Set the granted days for the year to exactly this. Use for "give her 20 vacation days".',
                required: false,
            ),
            new ToolProperty(
                name: 'adjust_days',
                type: PropertyType::NUMBER,
                description: 'Add this many days to the granted total — negative to take days away. Use for "give him '
                    . '2 extra days" without needing to know the current total.',
                required: false,
            ),
            new ToolProperty(
                name: 'accrued_days',
                type: PropertyType::NUMBER,
                description: 'Set the days earned so far this year (for monthly-accrual policies).',
                required: false,
            ),
            new ToolProperty(
                name: 'carried_over_days',
                type: PropertyType::NUMBER,
                description: 'Set the days rolled over from last year.',
                required: false,
            ),
            new ToolProperty(
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Why the balance changed — recorded on the employee\'s history.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $leave_type,
        ?string $employee_email = null,
        ?int $employee_id = null,
        ?int $year = null,
        int|float|null $entitled_days = null,
        int|float|null $adjust_days = null,
        int|float|null $accrued_days = null,
        int|float|null $carried_over_days = null,
        ?string $reason = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        if ($entitled_days === null && $adjust_days === null && $accrued_days === null && $carried_over_days === null) {
            return [
                'updated' => false,
                'message' => 'Nothing to change — pass entitled_days, adjust_days, accrued_days or carried_over_days. '
                    . 'To simply grant an employee a policy at its default days, use assign_leave_policy instead.',
            ];
        }

        $target = $this->resolveLeaveTargetOrError($employee_email, $employee_id, $leave_type);

        if (! isset($target['employee'])) {
            return $target;
        }

        return $this->writeLeaveBalance(
            employee: $target['employee'],
            leaveType: $target['leaveType'],
            year: $this->leaveYear($year),
            entitledDays: $this->daysOrNull($entitled_days),
            accruedDays: $this->daysOrNull($accrued_days),
            carriedOverDays: $this->daysOrNull($carried_over_days),
            adjustDays: $this->daysOrNull($adjust_days),
            reason: $reason,
        );
    }
}
