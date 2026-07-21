<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Services;

use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
use Kanvas\HumanResources\Leave\Models\LeaveBalance;
use Kanvas\HumanResources\Leave\Models\LeaveType;

class LeaveBalanceService
{
    /**
     * Get the employee's balance row for a leave type + year, creating it (seeded with the
     * type's annual entitlement) the first time it's needed.
     */
    public function getOrCreate(Employee $employee, LeaveType $leaveType, int $year): LeaveBalance
    {
        $balance = LeaveBalance::query()
            ->where('companies_id', $employee->companies_id)
            ->where('employee_id', $employee->getId())
            ->where('leave_type_id', $leaveType->getId())
            ->where('period_year', $year)
            ->where('is_deleted', 0)
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        $balance = new LeaveBalance();
        $balance->apps_id = $employee->apps_id;
        $balance->companies_id = $employee->companies_id;
        $balance->employee_id = $employee->getId();
        $balance->leave_type_id = $leaveType->getId();
        $balance->period_year = $year;
        $balance->entitled_days = $leaveType->accrual_method === AccrualMethodEnum::ANNUAL_ALLOTMENT->value
            ? ($leaveType->default_annual_days ?? 0.0)
            : 0.0;
        $balance->accrued_days = 0.0;
        $balance->carried_over_days = 0.0;
        $balance->used_days = 0.0;
        $balance->pending_days = 0.0;
        $balance->saveOrFail();

        return $balance;
    }
}
