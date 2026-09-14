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
 * Grants an employee a leave policy for a year — the step that was missing between "the policy exists"
 * and "request_leave succeeds", since a request is checked against a balance that nothing was creating
 * up front.
 *
 * Re-assigning is safe: an existing grant is reported back untouched rather than re-seeded, so the
 * agent can call this defensively without silently wiping days someone already used.
 */
#[AgentTool(name: 'Assign Leave Policy', category: 'human_resources')]
class AssignLeavePolicyTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'assign_leave_policy',
            description: 'Gives an employee a leave type for a year, granting them the policy\'s annual entitlement so '
                . 'they can actually request that leave. Admin only. Use this when request_leave fails for lack of '
                . 'balance, or right after onboarding someone. Safe to repeat: an existing grant comes back unchanged '
                . '(assigned=false) instead of being reset — use set_employee_leave_balance to change days.',
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
                description: 'The calendar year the grant applies to. Defaults to the current year.',
                required: false,
            ),
            new ToolProperty(
                name: 'entitled_days',
                type: PropertyType::NUMBER,
                description: 'Override the policy\'s annual days for this one employee (e.g. a pro-rated first year). '
                    . 'Leave empty to use the policy default.',
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
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $target = $this->resolveLeaveTargetOrError($employee_email, $employee_id, $leave_type);

        if (! isset($target['employee'])) {
            return $target;
        }

        $result = $this->writeLeaveBalance(
            employee: $target['employee'],
            leaveType: $target['leaveType'],
            year: $this->leaveYear($year),
            entitledDays: $this->daysOrNull($entitled_days),
            reason: 'Leave policy assigned',
        );

        if ($result['updated'] === true && $result['assigned'] === false && $entitled_days === null) {
            $result['message'] = 'This employee already had that policy for the year — days left as they were. Use '
                . 'set_employee_leave_balance to change them.';
        }

        return $result;
    }
}
