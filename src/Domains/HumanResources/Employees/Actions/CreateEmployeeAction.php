<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;

class CreateEmployeeAction
{
    public function __construct(
        protected readonly EmployeeData $data,
    ) {
    }

    public function execute(): Employee
    {
        return DB::connection('hr')->transaction(function () {
            $this->assertUserNotAlreadyEmployee();

            $employee = new Employee();
            $employee->apps_id = $this->data->app->getId();
            $employee->companies_id = $this->data->company->getId();
            $employee->users_id = $this->data->loginUser->getId();
            $employee->people_id = $this->data->people->getId();
            $employee->department_id = $this->data->department?->getId();
            $employee->position_id = $this->data->position->getId();
            $employee->manager_employee_id = $this->data->manager?->getId();
            $employee->employee_number = $this->data->employeeNumber;
            $employee->description = $this->data->description;
            $employee->employment_type = $this->data->employmentType->value;
            $employee->home_entity = $this->data->homeEntity;
            $employee->status = $this->data->status->value;
            $employee->hired_at = $this->data->hiredAt;
            $employee->end_is_voluntary = $this->data->endIsVoluntary;
            $employee->saveOrFail();

            $employee->emitLedgerEvent('employee.created', payload: [
                'status' => $employee->status,
                'employment_type' => $employee->employment_type,
            ]);

            return $employee;
        });
    }

    /**
     * A login user maps to at most one active employee per company+app (MySQL has no partial unique index).
     */
    private function assertUserNotAlreadyEmployee(): void
    {
        $alreadyLinked = Employee::query()
            ->fromApp($this->data->app)
            ->fromCompany($this->data->company)
            ->notDeleted()
            ->where('users_id', $this->data->loginUser->getId())
            ->exists();

        if ($alreadyLinked) {
            throw new HumanResourcesException('This user is already linked to an employee in this company.');
        }
    }
}
