<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Employee;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DateTimeInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\Actions\UpdateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\HumanResources\Employees\Enums\EmploymentTypeEnum;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Users\Models\Users;

class EmployeeMutation
{
    public function create(mixed $rootValue, array $request): Employee
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateEmployeeAction(
            $this->buildData($input, $app, $company),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Employee
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Employee $employee */
        $employee = Employee::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateEmployeeAction(
            $employee,
            $this->buildData($input, $app, $company, $employee),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $employee = Employee::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $employee->softDelete();
    }

    private function buildData(array $input, AppInterface $app, CompanyInterface $company, ?Employee $existing = null): EmployeeData
    {
        /** @var Users $loginUser */
        $loginUser = isset($input['users_id'])
            ? Users::getById((int) $input['users_id'], $app)
            : $existing->user;
        /** @var People $people */
        $people = isset($input['people_id'])
            ? People::getByIdFromCompanyApp((int) $input['people_id'], $company, $app)
            : $existing->people;
        /** @var Position $position */
        $position = isset($input['position_id'])
            ? Position::getByIdFromCompanyApp((int) $input['position_id'], $company, $app)
            : $existing->position;
        // The GraphQL Date scalar hydrates to a Carbon instance — normalize to a Y-m-d string for the DTO.
        $hiredAtRaw = $input['hired_at'] ?? $existing->hired_at;
        $hiredAt = $hiredAtRaw instanceof DateTimeInterface ? $hiredAtRaw->format('Y-m-d') : (string) $hiredAtRaw;

        return new EmployeeData(
            app: $app,
            company: $company,
            loginUser: $loginUser,
            people: $people,
            position: $position,
            hiredAt: $hiredAt,
            employmentType: isset($input['employment_type'])
                ? EmploymentTypeEnum::from($input['employment_type'])
                : ($existing !== null ? EmploymentTypeEnum::from($existing->employment_type) : EmploymentTypeEnum::EMPLOYEE),
            status: isset($input['status'])
                ? EmployeeStatusEnum::from($input['status'])
                : ($existing !== null ? EmployeeStatusEnum::from($existing->status) : EmployeeStatusEnum::ONBOARDING),
            department: $this->lookup(Department::class, $input['department_id'] ?? null, $company, $app) ?? $existing?->department,
            manager: $this->lookup(Employee::class, $input['manager_employee_id'] ?? null, $company, $app) ?? $existing?->parent,
            employeeNumber: $input['employee_number'] ?? $existing?->employee_number,
            description: $input['description'] ?? $existing?->description,
            homeEntity: $input['home_entity'] ?? $existing?->home_entity,
            endIsVoluntary: $input['end_is_voluntary'] ?? $existing?->end_is_voluntary,
        );
    }

    private function lookup(string $model, mixed $id, CompanyInterface $company, AppInterface $app): mixed
    {
        if ($id === null) {
            return null;
        }

        return $model::getByIdFromCompanyApp((int) $id, $company, $app);
    }
}
