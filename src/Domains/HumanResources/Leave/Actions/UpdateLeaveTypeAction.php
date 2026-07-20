<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Models\LeaveType;

class UpdateLeaveTypeAction
{
    public function __construct(
        protected readonly LeaveType $leaveType,
        protected readonly LeaveTypeData $data,
    ) {
    }

    public function execute(): LeaveType
    {
        return DB::connection('hr')->transaction(function () {
            $this->leaveType->name = $this->data->name;
            $this->leaveType->is_paid = $this->data->isPaid;
            $this->leaveType->accrual_method = $this->data->accrualMethod->value;
            $this->leaveType->default_annual_days = $this->data->defaultAnnualDays;
            $this->leaveType->carryover_max_days = $this->data->carryoverMaxDays;
            $this->leaveType->requires_approval = $this->data->requiresApproval;
            $this->leaveType->color = $this->data->color;
            $this->leaveType->is_active = $this->data->isActive;
            $this->leaveType->saveOrFail();

            return $this->leaveType;
        });
    }
}
