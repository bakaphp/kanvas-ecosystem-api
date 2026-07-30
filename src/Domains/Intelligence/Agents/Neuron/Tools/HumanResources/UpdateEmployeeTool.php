<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources;

use Kanvas\Guild\Customers\Actions\UpdatePeopleProfileAction;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Actions\UpdateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\HumanResources\Employees\Enums\EmploymentTypeEnum;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsAdminForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesEmployeeForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPositionAndDepartmentForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Edits an existing employee's HR fields (description, status, employment type, position, department,
 * manager, employee number, hire date) and their name on the People record. Admin only. It never
 * touches the linked LOGIN user account — name changes land on People (the employee's identity record),
 * not on the platform user. Only the fields passed are changed.
 */
#[AgentTool(name: 'Update Employee', category: 'human_resources')]
class UpdateEmployeeTool extends Tool
{
    use GuardsAdminForTool;
    use HasKanvasContext;
    use ResolvesEmployeeForTool;
    use ResolvesPositionAndDepartmentForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'update_employee',
            description: 'Updates an existing employee. HR fields: description, status (onboarding/active/on_leave/'
                . 'suspended/departed), employment_type (employee/contractor/shared), position (by title), department '
                . '(by name), manager (by email), employee_number, hire date. Profile fields (on the People record): '
                . 'first_name/last_name/middle_name, birthday, phone, address/city/state/zip. Admin only. Only the '
                . 'fields you pass are changed; the linked LOGIN user account is NEVER changed — name/contact/address '
                . 'edits land on the People record. Identify the employee by email or employee_id (call find_employee '
                . 'for a name). Returns updated=false on any problem.',
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
                name: 'description',
                type: PropertyType::STRING,
                description: 'Free-text note about the employee/role.',
                required: false,
            ),
            new ToolProperty(
                name: 'first_name',
                type: PropertyType::STRING,
                description: 'Update the employee\'s first name (on their People record, not their login account).',
                required: false,
            ),
            new ToolProperty(
                name: 'last_name',
                type: PropertyType::STRING,
                description: 'Update the employee\'s last name (on their People record, not their login account).',
                required: false,
            ),
            new ToolProperty(
                name: 'middle_name',
                type: PropertyType::STRING,
                description: 'Update the employee\'s middle name (on their People record).',
                required: false,
            ),
            new ToolProperty(
                name: 'birthday',
                type: PropertyType::STRING,
                description: 'The employee\'s date of birth, YYYY-MM-DD (on their People record).',
                required: false,
            ),
            new ToolProperty(
                name: 'phone',
                type: PropertyType::STRING,
                description: 'The employee\'s cellphone number (on their People record).',
                required: false,
            ),
            new ToolProperty(
                name: 'address',
                type: PropertyType::STRING,
                description: 'Street address line for the employee\'s default (home) address.',
                required: false,
            ),
            new ToolProperty(
                name: 'city',
                type: PropertyType::STRING,
                description: 'City for the default address.',
                required: false,
            ),
            new ToolProperty(
                name: 'state',
                type: PropertyType::STRING,
                description: 'State/province for the default address.',
                required: false,
            ),
            new ToolProperty(
                name: 'zip',
                type: PropertyType::STRING,
                description: 'Postal / ZIP code for the default address.',
                required: false,
            ),
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'One of: onboarding, active, on_leave, suspended, departed.',
                required: false,
            ),
            new ToolProperty(
                name: 'employment_type',
                type: PropertyType::STRING,
                description: 'One of: employee, contractor, shared.',
                required: false,
            ),
            new ToolProperty(
                name: 'position_title',
                type: PropertyType::STRING,
                description: 'Move the employee to this position (must already exist).',
                required: false,
            ),
            new ToolProperty(
                name: 'department_name',
                type: PropertyType::STRING,
                description: 'Move the employee to this department (must already exist).',
                required: false,
            ),
            new ToolProperty(
                name: 'manager_email',
                type: PropertyType::STRING,
                description: 'Set the employee\'s manager, identified by the manager\'s login email.',
                required: false,
            ),
            new ToolProperty(
                name: 'employee_number',
                type: PropertyType::STRING,
                description: 'The employee number / payroll id.',
                required: false,
            ),
            new ToolProperty(
                name: 'hired_at',
                type: PropertyType::STRING,
                description: 'Hire date, YYYY-MM-DD.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $employee_email = null,
        ?int $employee_id = null,
        ?string $description = null,
        ?string $status = null,
        ?string $employment_type = null,
        ?string $position_title = null,
        ?string $department_name = null,
        ?string $manager_email = null,
        ?string $employee_number = null,
        ?string $hired_at = null,
        ?string $first_name = null,
        ?string $last_name = null,
        ?string $middle_name = null,
        ?string $birthday = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $city = null,
        ?string $state = null,
        ?string $zip = null,
    ): array {
        if ($denied = $this->requireAdminOrError()) {
            return $denied;
        }

        $employee = $this->resolveEmployeeOrError($employee_email, $employee_id);
        if (is_array($employee)) {
            return $employee;
        }

        $profileChange = $first_name ?? $last_name ?? $middle_name ?? $birthday ?? $phone ?? $address ?? $city ?? $state ?? $zip;
        if ($profileChange !== null && $employee->people !== null) {
            new UpdatePeopleProfileAction(
                $employee->people,
                $this->app,
                firstname: $first_name,
                lastname: $last_name,
                middlename: $middle_name,
                dob: $birthday,
                phone: $phone,
                address: $address,
                city: $city,
                state: $state,
                zip: $zip,
            )->execute();
        }

        $status = $status !== null ? strtolower($status) : null;
        if ($status !== null && EmployeeStatusEnum::tryFrom($status) === null) {
            return ['updated' => false, 'message' => "Invalid status \"{$status}\". Use one of: onboarding, active, on_leave, suspended, departed."];
        }

        $employment_type = $employment_type !== null ? strtolower($employment_type) : null;
        if ($employment_type !== null && EmploymentTypeEnum::tryFrom($employment_type) === null) {
            return ['updated' => false, 'message' => "Invalid employment_type \"{$employment_type}\". Use one of: employee, contractor, shared."];
        }

        $position = $employee->position;
        if ($position_title !== null && $position_title !== '') {
            $position = $this->findPositionByTitle($position_title);
            if (! $position instanceof Position) {
                return ['updated' => false, 'message' => "No position titled \"{$position_title}\" exists. Create it with create_position first."];
            }
        }

        $department = $employee->department;
        if ($department_name !== null && $department_name !== '') {
            $department = $this->findDepartmentByName($department_name);
            if (! $department instanceof Department) {
                return ['updated' => false, 'message' => "No department named \"{$department_name}\" exists."];
            }
        }

        $manager = $employee->parent;
        if ($manager_email !== null && $manager_email !== '') {
            $resolved = $this->resolveEmployeeOrError($manager_email);
            if (is_array($resolved)) {
                return ['updated' => false, 'message' => "No employee found for manager email \"{$manager_email}\"."];
            }
            $manager = $resolved;
        }

        $updated = new UpdateEmployeeAction(
            $employee,
            new EmployeeData(
                app: $this->app,
                company: $this->company,
                loginUser: $employee->user,
                people: $employee->people,
                position: $position,
                hiredAt: $hired_at ?? (string) $employee->hired_at,
                employmentType: $employment_type !== null ? EmploymentTypeEnum::from($employment_type) : EmploymentTypeEnum::from($employee->employment_type),
                status: $status !== null ? EmployeeStatusEnum::from($status) : EmployeeStatusEnum::from($employee->status),
                department: $department instanceof Department ? $department : null,
                manager: $manager instanceof Employee ? $manager : null,
                employeeNumber: $employee_number ?? $employee->employee_number,
                description: $description ?? $employee->description,
                homeEntity: $employee->home_entity,
                endIsVoluntary: $employee->end_is_voluntary,
            ),
        )->execute();

        return [
            'updated' => true,
            'employee_id' => $updated->getId(),
            'name' => $employee->people?->name,
            // Use the resolved models, not the (stale, pre-save) relations on $updated.
            'position' => $position?->title,
            'department' => $department instanceof Department ? $department->name : null,
            'status' => $updated->status,
            'employment_type' => $updated->employment_type,
            'description' => $updated->description,
        ];
    }
}
