<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Services\EmployeeActivityService;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveBalanceAdjustment;
use Kanvas\HumanResources\Leave\Models\LeaveBalance;
use Kanvas\HumanResources\Leave\Services\LeaveBalanceService;

/**
 * The single write path for a leave balance outside of request/approve/cancel. Called with no day
 * values it just materialises the row from the leave type's annual entitlement — which is what
 * "assign this policy to this employee" means here, since the policy IS the leave type.
 */
class AdjustLeaveBalanceAction
{
    public function __construct(
        protected readonly LeaveBalanceAdjustment $data,
    ) {
    }

    public function execute(): LeaveBalance
    {
        return DB::connection('hr')->transaction(function () {
            $employee = $this->data->employee;

            $balance = new LeaveBalanceService()->getOrCreate($employee, $this->data->leaveType, $this->data->year);

            $assigned = $balance->wasRecentlyCreated;

            $entitled = ($this->data->entitledDays ?? $balance->entitled_days) + ($this->data->adjustEntitledDays ?? 0.0);
            $accrued = $this->data->accruedDays ?? $balance->accrued_days;
            $carried = $this->data->carriedOverDays ?? $balance->carried_over_days;

            $this->assertGranted(
                $entitled,
                $accrued,
                $carried,
                $balance,
            );

            $changed = $entitled !== $balance->entitled_days
                || $accrued !== $balance->accrued_days
                || $carried !== $balance->carried_over_days;

            $balance->entitled_days = $entitled;
            $balance->accrued_days = $accrued;
            $balance->carried_over_days = $carried;
            $balance->saveOrFail();

            if ($assigned || $changed) {
                $this->recordHistory($balance, $assigned);
            }

            return $balance;
        });
    }

    private function assertGranted(
        float $entitled,
        float $accrued,
        float $carried,
        LeaveBalance $balance,
    ): void {
        $components = [
            'Entitled' => $entitled,
            'Accrued' => $accrued,
            'Carried over' => $carried,
        ];

        foreach ($components as $label => $value) {
            if ($value < 0) {
                throw new HumanResourcesException($label . ' days cannot be negative.');
            }
        }

        $committed = $balance->used_days + $balance->pending_days;

        if ($entitled + $accrued + $carried < $committed) {
            throw new HumanResourcesException(sprintf(
                'Cannot lower the %s balance to %s day(s): %s day(s) are already used or pending for %s.',
                $this->data->leaveType->name,
                $entitled + $accrued + $carried,
                $committed,
                $this->data->year,
            ));
        }
    }

    private function recordHistory(LeaveBalance $balance, bool $assigned): void
    {
        $employee = $this->data->employee;
        $actor = $this->data->actor;

        $payload = [
            'leave_type' => $this->data->leaveType->name,
            'year' => $this->data->year,
            'entitled_days' => $balance->entitled_days,
            'accrued_days' => $balance->accrued_days,
            'carried_over_days' => $balance->carried_over_days,
            'available_days' => $balance->available_days,
            'reason' => $this->data->reason,
        ];

        $balance->emitLedgerEvent(
            $assigned ? 'leave.policy.assigned' : 'leave.balance.adjusted',
            payload: $payload,
            actorType: 'User',
            actorId: $actor?->getId() ?? $employee->users_id,
        );

        new EmployeeActivityService()->record(
            $employee,
            $assigned ? 'leave.policy.assigned' : 'leave.balance.adjusted',
            sprintf(
                $assigned
                    ? '%s leave assigned for %d — %s day(s) available'
                    : '%s balance adjusted for %d — %s day(s) available',
                $this->data->leaveType->name,
                $this->data->year,
                $balance->available_days,
            ),
            actor: $actor,
            context: $payload,
        );
    }
}
