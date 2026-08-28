<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Leave;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Actions\CancelLeaveRequestAction;
use Kanvas\HumanResources\Leave\Actions\DecideLeaveRequestAction;
use Kanvas\HumanResources\Leave\Actions\RequestLeaveAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveRequest as LeaveRequestData;
use Kanvas\HumanResources\Leave\Enums\LeaveDecisionEnum;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Leave\Models\LeaveType;

class LeaveRequestMutation
{
    use ResolvesActingContext;

    public function request(mixed $rootValue, array $request): LeaveRequest
    {
        $context = $this->actingContext();
        $input = $request['input'];

        $own = new EmployeeIdentityResolver()->fromUser($context->user, $context->company, $context->app);

        if (isset($input['employee_id'])) {
            /** @var Employee $employee */
            $employee = Employee::getByIdFromCompanyApp((int) $input['employee_id'], $context->company, $context->app);

            // Filing for someone else is an HR/admin action.
            if (! $context->user->isAdmin() && ($own === null || $own->getId() !== $employee->getId())) {
                throw new HumanResourcesException('You can only request leave for yourself.');
            }
        } else {
            $employee = $own;
        }

        if ($employee === null) {
            throw new HumanResourcesException('You are not set up as an employee yet — ask HR to add you.');
        }

        /** @var LeaveType $leaveType */
        $leaveType = LeaveType::getByIdFromCompanyApp((int) $input['leave_type_id'], $context->company, $context->app);

        return new RequestLeaveAction(
            new LeaveRequestData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                employee: $employee,
                leaveType: $leaveType,
                startDate: $this->normalizeDate($input['start_date']),
                endDate: $this->normalizeDate($input['end_date']),
                reason: $input['reason'] ?? null,
            ),
        )->execute();
    }

    public function decide(mixed $rootValue, array $request): LeaveRequest
    {
        $context = $this->actingContext();

        /** @var LeaveRequest $leaveRequest */
        $leaveRequest = LeaveRequest::getByIdFromCompanyApp((int) $request['id'], $context->company, $context->app);

        $approver = new EmployeeIdentityResolver()->fromUser($context->user, $context->company, $context->app);

        if (! $context->user->isAdmin() && ! $approver?->manages($leaveRequest->employee)) {
            throw new HumanResourcesException('You are not authorized to decide this leave request.');
        }

        return new DecideLeaveRequestAction(
            $leaveRequest,
            LeaveDecisionEnum::from($request['decision']),
            $approver,
            $request['note'] ?? null,
        )->execute();
    }

    public function cancel(mixed $rootValue, array $request): LeaveRequest
    {
        $context = $this->actingContext();

        /** @var LeaveRequest $leaveRequest */
        $leaveRequest = LeaveRequest::getByIdFromCompanyApp((int) $request['id'], $context->company, $context->app);

        if (! $context->user->isAdmin() && $leaveRequest->employee->users_id !== $context->user->getId()) {
            throw new HumanResourcesException('You can only cancel your own leave request.');
        }

        return new CancelLeaveRequestAction($leaveRequest)->execute();
    }
}
