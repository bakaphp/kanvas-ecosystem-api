<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Observers;

use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeActivityService;
use Kanvas\HumanResources\Employees\Services\EmployeeChannelService;
use Throwable;

class EmployeeObserver
{
    public function created(Employee $employee): void
    {
        // The activity channel + onboarding note are a best-effort side effect — never let a Social/
        // channel hiccup roll back the employee itself (the employee is the source of truth).
        try {
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
        } catch (Throwable $e) {
            report($e);
        }
    }

    public function updating(Employee $employee): void
    {
        $employee->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
