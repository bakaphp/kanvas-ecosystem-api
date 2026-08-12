<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesLeaveForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Self-service: files a time-off request for the CURRENT employee (the person talking). No admin needed
 * — this is an employee requesting their OWN leave, resolved from the requesting user, so it can never
 * file on behalf of someone else. The request lands as PENDING for a manager to approve.
 */
#[AgentTool(name: 'Request My Leave', category: 'human_resources')]
class RequestMyLeaveTool extends Tool implements HasRunKey
{
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'request_my_leave',
            description: 'Files YOUR OWN PENDING time-off request against a leave type, between a start and end date '
                . '(inclusive). It always requests for the person you are talking to — it takes no employee identifier. '
                . 'Returns created=false with a reason if your balance is insufficient or the leave type is unknown. '
                . 'The request goes to a manager to approve.',
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
    public function __invoke(string $leave_type, string $start_date, string $end_date, ?string $reason = null): array
    {
        $employee = new EmployeeIdentityResolver()->fromUser($this->user, $this->company, $this->app);

        if (! $employee instanceof Employee) {
            return [
                'created' => false,
                'message' => 'You are not set up as an employee yet — ask HR to add you.',
            ];
        }

        return $this->submitLeaveRequest($employee, $employee->user ?? $this->user, $leave_type, $start_date, $end_date, $reason);
    }
}
