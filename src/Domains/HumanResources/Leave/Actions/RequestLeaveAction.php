<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Services\EmployeeActivityService;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveRequest as LeaveRequestData;
use Kanvas\HumanResources\Leave\Enums\LeaveRequestStatusEnum;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Leave\Services\LeaveBalanceService;

class RequestLeaveAction
{
    public function __construct(
        protected readonly LeaveRequestData $data,
    ) {
    }

    public function execute(): LeaveRequest
    {
        return DB::connection('hr')->transaction(function () {
            $start = Carbon::parse($this->data->startDate);
            $end = Carbon::parse($this->data->endDate);

            if ($end->lt($start)) {
                throw new HumanResourcesException('End date must be on or after the start date.');
            }

            $days = (int) $start->diffInDays($end) + 1;
            $year = (int) $start->format('Y');

            $balance = new LeaveBalanceService()->getOrCreate($this->data->employee, $this->data->leaveType, $year);

            if ($balance->available_days < $days) {
                throw new HumanResourcesException(sprintf(
                    'Not enough %s balance: %s day(s) available, %s requested.',
                    $this->data->leaveType->name,
                    $balance->available_days,
                    $days,
                ));
            }

            $request = new LeaveRequest();
            $request->apps_id = $this->data->app->getId();
            $request->companies_id = $this->data->company->getId();
            $request->employee_id = $this->data->employee->getId();
            $request->leave_type_id = $this->data->leaveType->getId();
            $request->start_date = $start->toDateString();
            $request->end_date = $end->toDateString();
            $request->days = $days;
            $request->reason = $this->data->reason;
            $request->status = LeaveRequestStatusEnum::PENDING->value;
            $request->saveOrFail();

            $balance->pending_days += $days;
            $balance->saveOrFail();

            $request->emitLedgerEvent('leave.requested', payload: [
                'days' => $days,
                'leave_type' => $this->data->leaveType->name,
            ], actorType: 'User', actorId: $this->data->employee->users_id);

            new EmployeeActivityService()->record(
                $this->data->employee,
                'leave.requested',
                sprintf('Requested %d day(s) of %s (%s → %s)', $days, $this->data->leaveType->name, $start->toDateString(), $end->toDateString()),
                actor: $this->data->employee->user,
                context: ['days' => $days, 'leave_type' => $this->data->leaveType->name],
            );

            return $request;
        });
    }
}
