<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Seats\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Seats\DataTransferObject\SeatAssignment as SeatAssignmentData;
use Kanvas\HumanResources\Seats\Models\SeatAssignment;

class AssignSeatAction
{
    public function __construct(
        protected readonly SeatAssignmentData $data,
    ) {
    }

    public function execute(): SeatAssignment
    {
        return DB::connection('hr')->transaction(function () {
            if ($this->data->isPrimary) {
                $this->demoteExistingPrimarySeats();
            }

            $seat = new SeatAssignment();
            $seat->apps_id = $this->data->app->getId();
            $seat->companies_id = $this->data->company->getId();
            $seat->users_id = $this->data->user->getId();
            $seat->employee_id = $this->data->employee->getId();
            $seat->department_id = $this->data->department->getId();
            $seat->allocation_pct = $this->data->allocationPct;
            $seat->is_primary = $this->data->isPrimary;
            $seat->effective_from = $this->data->effectiveFrom ?? now()->toDateString();
            $seat->saveOrFail();

            if ($this->data->isPrimary) {
                $employee = $this->data->employee;
                $employee->department_id = $this->data->department->getId();
                $employee->saveOrFail();
            }

            return $seat;
        });
    }

    /**
     * One active primary seat per employee — demote the others so the new one becomes primary.
     */
    private function demoteExistingPrimarySeats(): void
    {
        SeatAssignment::query()
            ->where('employee_id', $this->data->employee->getId())
            ->where('is_primary', 1)
            ->whereNull('effective_to')
            ->where('is_deleted', 0)
            ->update(['is_primary' => 0]);
    }
}
