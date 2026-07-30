<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Actions\RequestLeaveAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveRequest as LeaveRequestData;
use Kanvas\HumanResources\Leave\Models\LeaveBalance;
use Kanvas\HumanResources\Leave\Models\LeaveType;

/**
 * Shared leave read/write for the HR tools, so the "for others" (admin) and "for myself" (self-service)
 * tool pairs never drift in balance-output shape or the balance-check behaviour behind a request.
 */
trait HandlesLeaveForTool
{
    /**
     * @return list<array<string, mixed>>
     */
    protected function leaveBalancesFor(Employee $employee, ?int $year): array
    {
        return $employee->leaveBalances()
            ->when($year !== null, fn (Builder $q): Builder => $q->where('period_year', $year))
            ->get()
            ->map(fn (LeaveBalance $balance): array => [
                'leave_type' => $balance->leaveType?->name,
                'available_days' => $balance->available_days,
                'pending_days' => $balance->pending_days,
                'used_days' => $balance->used_days,
                'entitled_days' => $balance->entitled_days,
                'year' => $balance->period_year,
            ])->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function submitLeaveRequest(
        Employee $employee,
        UserInterface $actor,
        string $leaveType,
        string $startDate,
        string $endDate,
        ?string $reason
    ): array {
        try {
            /** @var LeaveType $type */
            $type = LeaveType::getByNameFromCompanyApp($leaveType, $this->company, $this->app);
        } catch (ModelNotFoundException) {
            return ['created' => false, 'message' => "No leave type named \"{$leaveType}\" exists for this company."];
        }

        try {
            $request = new RequestLeaveAction(
                new LeaveRequestData(
                    app: $this->app,
                    company: $this->company,
                    user: $actor,
                    employee: $employee,
                    leaveType: $type,
                    startDate: $startDate,
                    endDate: $endDate,
                    reason: $reason,
                ),
            )->execute();
        } catch (HumanResourcesException $e) {
            return ['created' => false, 'message' => $e->getMessage()];
        }

        return [
            'created' => true,
            'leave_request_id' => $request->getId(),
            'status' => $request->status,
            'days' => $request->days,
            'leave_type' => $type->name,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }
}
