<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Enums\LeaveRequestStatusEnum;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Leave\Services\LeaveBalanceService;

class CancelLeaveRequestAction
{
    public function __construct(
        protected readonly LeaveRequest $request,
    ) {
    }

    public function execute(): LeaveRequest
    {
        return DB::connection('hr')->transaction(function () {
            $status = $this->request->status;

            if (in_array($status, [LeaveRequestStatusEnum::CANCELLED->value, LeaveRequestStatusEnum::REJECTED->value], true)) {
                throw new HumanResourcesException('This leave request cannot be cancelled.');
            }

            $year = (int) $this->request->start_date->format('Y');
            $balance = new LeaveBalanceService()->getOrCreate($this->request->employee, $this->request->leaveType, $year);

            if ($status === LeaveRequestStatusEnum::PENDING->value) {
                $balance->pending_days -= $this->request->days;
            } elseif ($status === LeaveRequestStatusEnum::APPROVED->value) {
                $balance->used_days -= $this->request->days;
            }

            $balance->saveOrFail();

            $this->request->status = LeaveRequestStatusEnum::CANCELLED->value;
            $this->request->saveOrFail();

            return $this->request;
        });
    }
}
