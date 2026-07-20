<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Observers;

use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeActivityService;
use Kanvas\HumanResources\Employees\Services\EmployeeChannelService;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        new EmployeeChannelService()->findOrCreateForEmployee(
            $employee,
            $employee->app,
            $employee->company,
        );

        new EmployeeActivityService()->record(
            $employee,
            'employee.onboarded',
            'Employee onboarded' . ($employee->position ? ' as ' . (string) $employee->position->title : ''),
            context: ['status' => $employee->status],
        );
    }

    public function updating(Employee $employee): void
    {
        $employee->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
