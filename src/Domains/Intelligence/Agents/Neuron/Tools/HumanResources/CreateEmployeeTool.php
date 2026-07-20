<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Actions\CreatePeopleFromUserAction;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Enums\EmploymentTypeEnum;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPositionAndDepartmentForTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Onboards an existing platform user as an HR employee — the "fill in this person's HR info" flow that
 * lets HR staff (or the agent on their behalf) build out the roster by chat. The user must already exist
 * as a Kanvas login; the tool resolves/creates their People record, links the position by title, and runs
 * the same CreateEmployeeAction as the mutation (so the one-employee-per-user guard still holds).
 */
#[AgentTool(name: 'Create Employee', category: 'human_resources')]
class CreateEmployeeTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ResolvesPositionAndDepartmentForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_employee',
            description: 'Onboards an existing platform user (by their login email) as an HR employee: links their '
                . 'position (by title) and optional department (by name) and hire date. Admin only. Returns '
                . 'created=false with a reason when you are not an admin, the user does not exist, the position is '
                . 'unknown, or the user is already an employee.',
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
                name: 'user_email',
                type: PropertyType::STRING,
                description: 'Login email of the existing platform user to onboard.',
                required: true,
            ),
            new ToolProperty(
                name: 'position_title',
                type: PropertyType::STRING,
                description: 'The position/job title to assign (must already exist).',
                required: true,
            ),
            new ToolProperty(
                name: 'hired_at',
                type: PropertyType::STRING,
                description: 'Hire date, YYYY-MM-DD.',
                required: true,
            ),
            new ToolProperty(
                name: 'department_name',
                type: PropertyType::STRING,
                description: 'Optional department name to place the employee in.',
                required: false,
            ),
            new ToolProperty(
                name: 'employment_type',
                type: PropertyType::STRING,
                description: 'One of employee, contractor, intern. Defaults to employee.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional free-text note about the employee/role.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $user_email,
        string $position_title,
        string $hired_at,
        ?string $department_name = null,
        ?string $employment_type = null,
        ?string $description = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        try {
            $loginUser = Users::getByEmail($user_email);
        } catch (ModelNotFoundException) {
            return [
                'created' => false,
                'message' => "No platform user exists with email {$user_email}. Create the user account first.",
            ];
        }

        $position = $this->findPositionByTitle($position_title);
        if (! $position instanceof Position) {
            return [
                'created' => false,
                'message' => "No position titled \"{$position_title}\" exists. Create the position first.",
            ];
        }

        $department = $this->findDepartmentByName($department_name);
        $people = new CreatePeopleFromUserAction($this->app, $this->company->defaultBranch, $loginUser)->execute();

        try {
            $employee = new CreateEmployeeAction(
                new EmployeeData(
                    app: $this->app,
                    company: $this->company,
                    loginUser: $loginUser,
                    people: $people,
                    position: $position,
                    hiredAt: $hired_at,
                    employmentType: EmploymentTypeEnum::tryFrom($employment_type ?? '') ?? EmploymentTypeEnum::EMPLOYEE,
                    department: $department instanceof Department ? $department : null,
                    description: $description,
                ),
            )->execute();
        } catch (HumanResourcesException $e) {
            return [
                'created' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'created' => true,
            'employee_id' => $employee->getId(),
            'name' => $people->name,
            'email' => $user_email,
            'position' => $position->title,
            'department' => $department?->name,
            'status' => $employee->status,
        ];
    }
}
