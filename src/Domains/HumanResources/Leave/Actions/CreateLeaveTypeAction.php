<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Models\LeaveType;

class CreateLeaveTypeAction
{
    public function __construct(
        protected readonly LeaveTypeData $data,
    ) {
    }

    public function execute(): LeaveType
    {
        return DB::connection('hr')->transaction(function () {
            $type = new LeaveType();
            $type->apps_id = $this->data->app->getId();
            $type->companies_id = $this->data->company->getId();
            $type->users_id = $this->data->user->getId();
            $type->name = $this->data->name;
            $type->is_paid = $this->data->isPaid;
            $type->accrual_method = $this->data->accrualMethod->value;
            $type->default_annual_days = $this->data->defaultAnnualDays;
            $type->carryover_max_days = $this->data->carryoverMaxDays;
            $type->requires_approval = $this->data->requiresApproval;
            $type->color = $this->data->color;
            $type->is_active = $this->data->isActive;
            $type->saveOrFail();

            return $type;
        });
    }
}
