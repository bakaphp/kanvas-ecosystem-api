<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

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
 * Answers "how many vacation/leave days does <employee> have left?" — the self-service question at the
 * heart of R1. Returns each leave type's available / pending / used / entitled days for the employee,
 * resolved by email or employee id.
 */
#[AgentTool(name: 'Get Employee Leave Balance', category: 'human_resources')]
class GetEmployeeLeaveBalanceTool extends Tool implements HasRunKey
{
    use HandlesLeaveForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_employee_leave_balance',
            description: 'Returns an employee\'s leave balances (available, pending, used and entitled days per leave '
                . 'type) for a year. Use this to answer "how many vacation days do I / does <person> have left?". '
                . 'Identify the employee by email or employee_id — call find_employee first if you only have a name.',
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
                description: 'The calendar year to report. Defaults to the current year.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $employee_email = null, ?int $employee_id = null, ?int $year = null): array
    {
        $employee = $this->resolveEmployeeOrError($employee_email, $employee_id);
        if (is_array($employee)) {
            return $employee;
        }

        return [
            'employee_id' => $employee->getId(),
            'name' => $employee->people?->name,
            'year' => $year,
            'balances' => $this->leaveBalancesFor($employee, $year),
        ];
    }
}
