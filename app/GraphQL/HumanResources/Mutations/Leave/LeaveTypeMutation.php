<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Leave;

use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Leave\Actions\CreateLeaveTypeAction;
use Kanvas\HumanResources\Leave\Actions\UpdateLeaveTypeAction;
use Kanvas\HumanResources\Leave\DataTransferObject\LeaveType as LeaveTypeData;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
use Kanvas\HumanResources\Leave\Models\LeaveType;

class LeaveTypeMutation
{
    public function create(mixed $rootValue, array $request): LeaveType
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        return new CreateLeaveTypeAction(
            new LeaveTypeData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                isPaid: $input['is_paid'] ?? true,
                accrualMethod: isset($input['accrual_method'])
                    ? AccrualMethodEnum::from($input['accrual_method'])
                    : AccrualMethodEnum::ANNUAL_ALLOTMENT,
                defaultAnnualDays: $input['default_annual_days'] ?? null,
                carryoverMaxDays: $input['carryover_max_days'] ?? null,
                requiresApproval: $input['requires_approval'] ?? true,
                color: $input['color'] ?? null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): LeaveType
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var LeaveType $type */
        $type = LeaveType::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return new UpdateLeaveTypeAction(
            $type,
            new LeaveTypeData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $type->name,
                isPaid: $input['is_paid'] ?? $type->is_paid,
                accrualMethod: isset($input['accrual_method'])
                    ? AccrualMethodEnum::from($input['accrual_method'])
                    : AccrualMethodEnum::from($type->accrual_method),
                defaultAnnualDays: $input['default_annual_days'] ?? $type->default_annual_days,
                carryoverMaxDays: $input['carryover_max_days'] ?? $type->carryover_max_days,
                requiresApproval: $input['requires_approval'] ?? $type->requires_approval,
                color: $input['color'] ?? $type->color,
                isActive: $input['is_active'] ?? $type->is_active,
            ),
        )->execute();
    }
}
