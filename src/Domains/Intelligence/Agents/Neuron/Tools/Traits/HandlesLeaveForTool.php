<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\HumanResources\Leave\Actions\AdjustLeaveBalanceAction;
use Kanvas\HumanResources\Leave\Actions\RequestLeaveAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveBalanceAdjustment;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveRequest as LeaveRequestData;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
use Kanvas\HumanResources\Leave\Models\LeaveBalance;
use Kanvas\HumanResources\Leave\Models\LeaveRequest;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Kanvas\Users\Models\Users;

/**
 * Shared leave read/write for the HR tools, so the "for others" (admin) and "for myself" (self-service)
 * tool pairs never drift in balance-output shape or the balance-check behaviour behind a request.
 */
trait HandlesLeaveForTool
{
    private ?Employee $actingEmployee = null;
    private bool $actingEmployeeResolved = false;

    /**
     * @return list<array<string, mixed>>
     */
    protected function leaveBalancesFor(Employee $employee, ?int $year): array
    {
        return $employee->leaveBalances()
            ->when($year !== null, fn (Builder $q): Builder => $q->where('period_year', $year))
            ->get()
            ->map(fn (LeaveBalance $balance): array => $this->presentBalance($balance))
            ->all();
    }

    /**
     * NeuronAI calls __invoke from a strict_types file with the decoded JSON values untouched, so a
     * PropertyType::NUMBER the model sends as `15` arrives as an int. Typing the params `int|float`
     * and narrowing here is what keeps that from being a TypeError that kills the whole turn.
     */
    protected function daysOrNull(int|float|null $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * @return AccrualMethodEnum|array{created: false, message: string}
     */
    protected function resolveAccrualMethod(?string $method): AccrualMethodEnum|array
    {
        if ($method === null || $method === '') {
            return AccrualMethodEnum::ANNUAL_ALLOTMENT;
        }

        $accrual = AccrualMethodEnum::tryFrom(strtolower($method));

        if ($accrual === null) {
            return [
                'created' => false,
                'message' => sprintf(
                    'Unknown accrual method "%s". Valid values: %s.',
                    $method,
                    implode(', ', array_column(AccrualMethodEnum::cases(), 'value')),
                ),
            ];
        }

        return $accrual;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentBalance(LeaveBalance $balance, ?string $leaveTypeName = null): array
    {
        return [
            'leave_type' => $leaveTypeName ?? $balance->leaveType?->name,
            'available_days' => $balance->available_days,
            'pending_days' => $balance->pending_days,
            'used_days' => $balance->used_days,
            'entitled_days' => $balance->entitled_days,
            'accrued_days' => $balance->accrued_days,
            'carried_over_days' => $balance->carried_over_days,
            'year' => $balance->period_year,
        ];
    }

    /**
     * @return LeaveType|array{status: string, message: string}
     */
    protected function resolveLeaveTypeOrError(string $name): LeaveType|array
    {
        try {
            /** @var LeaveType $type */
            $type = LeaveType::getByNameFromCompanyApp($name, $this->company, $this->app);

            return $type;
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No leave type named \"{$name}\" exists for this company. Call list_leave_types to see "
                    . 'the real names, or create_leave_type to define it. Do not invent one.',
            ];
        }
    }

    protected function leaveYear(?int $year): int
    {
        return $year ?? Carbon::now()->year;
    }

    /**
     * Both balance-writing tools need the same two lookups before they can act, and each returns the
     * tool-shaped error rather than throwing. Resolving them together is what stops one tool from
     * growing a guard the other silently lacks.
     *
     * @return array{employee: Employee, leaveType: LeaveType}|array{updated: false, message: string}
     */
    protected function resolveLeaveTargetOrError(?string $employeeEmail, ?int $employeeId, string $leaveType): array
    {
        $employee = $this->resolveEmployeeOrError($employeeEmail, $employeeId);

        if (! $employee instanceof Employee) {
            return ['updated' => false, 'message' => $employee['message']];
        }

        $type = $this->resolveLeaveTypeOrError($leaveType);

        if (! $type instanceof LeaveType) {
            return ['updated' => false, 'message' => $type['message']];
        }

        return ['employee' => $employee, 'leaveType' => $type];
    }

    /**
     * @return LeaveRequest|array{updated: false, message: string}
     */
    protected function resolveLeaveRequestOrError(int $requestId): LeaveRequest|array
    {
        $request = LeaveRequest::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('id', $requestId)
            ->first();

        if ($request instanceof LeaveRequest) {
            return $request;
        }

        return [
            'updated' => false,
            'message' => "No leave request with id {$requestId} exists for this company. Call list_leave_requests "
                . 'to get real ids. Do not invent one.',
        ];
    }

    /**
     * The human the tool is acting for: the conversation's user when the caller set one, otherwise the
     * tool's own context user. An agent's own user is usually an admin, so the distinction is what keeps
     * a non-admin human from driving a privileged write through the agent.
     */
    protected function actingUser(): ?Users
    {
        $actor = $this->requestingUser ?? $this->user;

        return $actor instanceof Users ? $actor : null;
    }

    protected function actingEmployee(): ?Employee
    {
        if ($this->actingEmployeeResolved) {
            return $this->actingEmployee;
        }

        $this->actingEmployeeResolved = true;
        $actor = $this->actingUser();

        if ($actor === null) {
            return null;
        }

        return $this->actingEmployee = new EmployeeIdentityResolver()->fromUser($actor, $this->company, $this->app);
    }

    /**
     * Mirrors LeaveRequestMutation@decide: an admin, or the employee's own manager up the reporting
     * line. Deciding is the one leave write a non-admin is meant to do, so the blanket admin guard the
     * other write tools use would lock every real manager out of approving their own team's time off.
     *
     * @return array{updated: false, message: string}|null
     */
    protected function requireLeaveDeciderOrError(LeaveRequest $request): ?array
    {
        if ($this->actingUser()?->isAdmin() === true) {
            return null;
        }

        if ($this->actingEmployee()?->manages($request->employee) === true) {
            return null;
        }

        return [
            'updated' => false,
            'message' => 'Only a company administrator or the employee\'s own manager can decide this leave request.',
        ];
    }

    /**
     * Mirrors LeaveRequestMutation@cancel: an admin, or the person whose leave it is.
     *
     * @return array{updated: false, message: string}|null
     */
    protected function requireLeaveCancellerOrError(LeaveRequest $request): ?array
    {
        $actor = $this->actingUser();

        if ($actor?->isAdmin() === true) {
            return null;
        }

        if ($actor !== null && $request->employee->users_id === $actor->getId()) {
            return null;
        }

        return [
            'updated' => false,
            'message' => 'Only a company administrator or the employee who filed it can cancel this leave request.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentLeaveRequest(LeaveRequest $request): array
    {
        return [
            'leave_request_id' => $request->getId(),
            'employee_id' => $request->employee_id,
            'employee' => $request->employee?->people?->name,
            'leave_type' => $request->leaveType?->name,
            'start_date' => $request->start_date?->toDateString(),
            'end_date' => $request->end_date?->toDateString(),
            'days' => $request->days,
            'status' => $request->status,
            'reason' => $request->reason,
        ];
    }

    /**
     * Both the "assign a policy" and the "set a balance" tools land here — the difference is only which
     * day components the caller names, so keeping one path stops the two from drifting on guards.
     *
     * @return array<string, mixed>
     */
    protected function writeLeaveBalance(
        Employee $employee,
        LeaveType $leaveType,
        int $year,
        ?float $entitledDays = null,
        ?float $accruedDays = null,
        ?float $carriedOverDays = null,
        ?float $adjustDays = null,
        ?string $reason = null,
    ): array {
        try {
            $balance = new AdjustLeaveBalanceAction(
                new LeaveBalanceAdjustment(
                    employee: $employee,
                    leaveType: $leaveType,
                    year: $year,
                    actor: $this->actingUser(),
                    entitledDays: $entitledDays,
                    accruedDays: $accruedDays,
                    carriedOverDays: $carriedOverDays,
                    adjustEntitledDays: $adjustDays,
                    reason: $reason,
                ),
            )->execute();
        } catch (HumanResourcesException $e) {
            return ['updated' => false, 'message' => $e->getMessage()];
        }

        return [
            'updated' => true,
            'assigned' => $balance->wasRecentlyCreated,
            'employee_id' => $employee->getId(),
            'balance' => $this->presentBalance($balance, $leaveType->name),
        ];
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
        $type = $this->resolveLeaveTypeOrError($leaveType);

        if (is_array($type)) {
            return ['created' => false, 'message' => $type['message']];
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
