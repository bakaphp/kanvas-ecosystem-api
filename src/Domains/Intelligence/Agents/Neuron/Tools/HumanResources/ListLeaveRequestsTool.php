<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Leave\Enums\LeaveRequestStatusEnum;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
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
 * Answers "who has time off pending?" and, just as importantly, hands back the request ids that
 * decide_leave and cancel_leave need — without this the agent can file requests it can never find
 * again.
 */
#[AgentTool(name: 'List Leave Requests', category: 'human_resources')]
class ListLeaveRequestsTool extends Tool implements HasRunKey
{
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use TrackByInputs;

    private const int MAX_RESULTS = 50;

    public function __construct()
    {
        parent::__construct(
            name: 'list_leave_requests',
            description: 'Lists time-off requests with their ids, employee, dates, days and status. Filter by status '
                . '(pending/approved/rejected/cancelled) and/or a single employee. Call this to answer "who has leave '
                . 'pending?" and to get the leave_request_id before calling decide_leave or cancel_leave.',
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
                name: 'status',
                type: PropertyType::STRING,
                description: 'Filter by status: pending, approved, rejected or cancelled. Omit for all.',
                required: false,
            ),
            new ToolProperty(
                name: 'employee_email',
                type: PropertyType::STRING,
                description: 'Only this employee\'s requests, by login email.',
                required: false,
            ),
            new ToolProperty(
                name: 'employee_id',
                type: PropertyType::INTEGER,
                description: 'Only this employee\'s requests, by employee id (from find_employee).',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max requests to return. Defaults to 25, capped at 50.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $status = null,
        ?string $employee_email = null,
        ?int $employee_id = null,
        ?int $limit = null,
    ): array {
        $wanted = $status === null || $status === ''
            ? null
            : LeaveRequestStatusEnum::tryFrom(strtolower($status));

        if ($status !== null && $status !== '' && $wanted === null) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'Unknown status "%s". Valid values: %s.',
                    $status,
                    implode(', ', array_column(LeaveRequestStatusEnum::cases(), 'value')),
                ),
            ];
        }

        $employeeFilter = null;

        if ($employee_email !== null || $employee_id !== null) {
            $employee = $this->resolveEmployeeOrError($employee_email, $employee_id);

            if (! $employee instanceof Employee) {
                return $employee;
            }

            $employeeFilter = $employee->getId();
        }

        $requests = LeaveRequest::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when(
                $wanted !== null,
                fn (Builder $query): Builder => $query->where('status', $wanted->value),
            )
            ->when(
                $employeeFilter !== null,
                fn (Builder $query): Builder => $query->where('employee_id', $employeeFilter),
            )
            ->with(['employee.people', 'leaveType'])
            ->orderByDesc('start_date')
            ->limit(max(1, min(self::MAX_RESULTS, $limit ?? 25)))
            ->get()
            ->map(fn (LeaveRequest $request): array => $this->presentLeaveRequest($request))
            ->all();

        return [
            'count' => count($requests),
            'leave_requests' => $requests,
            'note' => $requests === []
                ? 'No leave requests match. Do NOT call list_leave_requests again with the same filters.'
                : 'Use leave_request_id with decide_leave or cancel_leave.',
        ];
    }
}
