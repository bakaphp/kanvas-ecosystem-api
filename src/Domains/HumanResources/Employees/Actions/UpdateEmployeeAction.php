<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;

class UpdateEmployeeAction
{
    public function __construct(
        protected readonly Employee $employee,
        protected readonly EmployeeData $data,
    ) {
    }

    public function execute(): Employee
    {
        return DB::connection('hr')->transaction(function () {
            $this->employee->people_id = $this->data->people->getId();
            $this->employee->department_id = $this->data->department?->getId();
            $this->employee->position_id = $this->data->position->getId();
            $this->employee->manager_employee_id = $this->data->manager?->getId();
            $this->employee->employee_number = $this->data->employeeNumber;
            $this->employee->description = $this->data->description;
            $this->employee->employment_type = $this->data->employmentType->value;
            $this->employee->home_entity = $this->data->homeEntity;
            $this->employee->status = $this->data->status->value;
            $this->employee->hired_at = $this->data->hiredAt;
            $this->employee->end_is_voluntary = $this->data->endIsVoluntary;
            $this->employee->saveOrFail();

            return $this->employee;
        });
    }
}
