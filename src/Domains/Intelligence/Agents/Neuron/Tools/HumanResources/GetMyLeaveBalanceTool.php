<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesLeaveForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Self-service: returns the CURRENT employee's own leave balances ("how many vacation days do I have
 * left?"). The employee is resolved from the requesting user — it can only ever read the caller's own
 * balance, never anyone else's.
 */
#[AgentTool(name: 'Get My Leave Balance', category: 'human_resources')]
class GetMyLeaveBalanceTool extends Tool
{
    use HandlesLeaveForTool;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'get_my_leave_balance',
            description: 'Returns YOUR own leave balances (available, pending, used and entitled days per leave type) '
                . 'for a year. Use this to answer "how many vacation/sick/personal days do I have left?". It always '
                . 'reads the balance of the person you are talking to — it takes no employee identifier.',
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
                name: 'year',
                type: PropertyType::INTEGER,
                description: 'The calendar year to report. Defaults to the current year.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $year = null): array
    {
        $employee = new EmployeeIdentityResolver()->fromUser($this->user, $this->company, $this->app);

        if (! $employee instanceof Employee) {
            return [
                'status' => 'not_employee',
                'message' => 'You are not set up as an employee yet — ask HR to add you.',
            ];
        }

        return [
            'employee_id' => $employee->getId(),
            'year' => $year,
            'balances' => $this->leaveBalancesFor($employee, $year),
        ];
    }
}
