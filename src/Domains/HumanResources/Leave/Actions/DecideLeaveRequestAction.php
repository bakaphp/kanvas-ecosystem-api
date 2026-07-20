<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Enums\LeaveDecisionEnum;
use Kanvas\HumanResources\Leave\Enums\LeaveRequestStatusEnum;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Leave\Services\LeaveBalanceService;

class DecideLeaveRequestAction
{
    public function __construct(
        protected readonly LeaveRequest $request,
        protected readonly LeaveDecisionEnum $decision,
        protected readonly ?Employee $approver = null,
        protected readonly ?string $note = null,
    ) {
    }

    public function execute(): LeaveRequest
    {
        return DB::connection('hr')->transaction(function () {
            if ($this->request->status !== LeaveRequestStatusEnum::PENDING->value) {
                throw new HumanResourcesException('This leave request has already been decided.');
            }

            $year = (int) $this->request->start_date->format('Y');
            $balance = new LeaveBalanceService()->getOrCreate($this->request->employee, $this->request->leaveType, $year);

            if ($this->decision === LeaveDecisionEnum::APPROVE) {
                $this->request->status = LeaveRequestStatusEnum::APPROVED->value;
                $balance->pending_days -= $this->request->days;
                $balance->used_days += $this->request->days;
            } else {
                $this->request->status = LeaveRequestStatusEnum::REJECTED->value;
                $balance->pending_days -= $this->request->days;
            }

            $balance->saveOrFail();

            $this->request->approver_employee_id = $this->approver?->getId();
            $this->request->decided_at = now();
            $this->request->saveOrFail();

            $eventType = $this->decision === LeaveDecisionEnum::APPROVE ? 'leave.approved' : 'leave.rejected';
            $this->request->emitLedgerEvent($eventType, payload: [
                'days' => $this->request->days,
            ], actorType: 'User', actorId: $this->approver?->users_id);

            return $this->request;
        });
    }
}
