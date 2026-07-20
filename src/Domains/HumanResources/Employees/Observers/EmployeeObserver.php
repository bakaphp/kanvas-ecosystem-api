<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Observers;

use Kanvas\HumanResources\Employees\Models\Employee;

class EmployeeObserver
{
    public function updating(Employee $employee): void
    {
        $employee->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
