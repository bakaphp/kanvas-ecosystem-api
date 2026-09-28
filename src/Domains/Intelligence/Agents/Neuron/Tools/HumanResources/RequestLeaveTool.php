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
 * Files a time-off request for an employee — the "request a day off" flow. It runs the same
 * RequestLeaveAction as the GraphQL mutation, so it enforces the same balance check (returns a
 * structured error, not a crash, when the employee lacks the days). The request lands as PENDING —
 * decide_leave is what approves or rejects it.
 */
#[AgentTool(name: 'Request Leave', category: 'human_resources')]
class RequestLeaveTool extends Tool implements HasRunKey
{
    use GuardsAdminForTool;
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'request_leave',
            description: 'Files a PENDING time-off request for an employee against a leave type, between a start and '
                . 'end date (inclusive). Admin only. Identify the employee by email or employee_id (call find_employee '
                . 'first for a name). Returns created=false with a reason if you are not an admin, the balance is '
                . 'insufficient, or the leave type is unknown.',
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
                description: 'The leave type name, e.g. "Vacation" or "Sick".',
                required: true,
            ),
            new ToolProperty(
                name: 'start_date',
                type: PropertyType::STRING,
                description: 'First day off, YYYY-MM-DD.',
                required: true,
            ),
            new ToolProperty(
                name: 'end_date',
                type: PropertyType::STRING,
                description: 'Last day off (inclusive), YYYY-MM-DD.',
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
                name: 'reason',
                type: PropertyType::STRING,
                description: 'Optional reason for the request.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $leave_type,
        string $start_date,
        string $end_date,
        ?string $employee_email = null,
        ?int $employee_id = null,
        ?string $reason = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $employee = $this->resolveEmployeeOrError($employee_email, $employee_id);
        if (is_array($employee)) {
            return $employee;
        }

        return $this->submitLeaveRequest($employee, $employee->user ?? $this->user, $leave_type, $start_date, $end_date, $reason);
    }
}
