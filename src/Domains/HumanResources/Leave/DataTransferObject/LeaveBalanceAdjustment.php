<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Spatie\LaravelData\Data;

/**
 * Every "days" property is optional and null means "leave this component alone" — the balance is the
 * sum of three independently-managed buckets, so a caller that only grants entitlement must not
 * silently zero out an accrual or a carryover someone else wrote.
 *
 * $actor is passed separately on purpose: the employee's own user is the person the balance belongs
 * to, not the admin granting it.
 */
class LeaveBalanceAdjustment extends Data
{
    public function __construct(
        public readonly Employee $employee,
        public readonly LeaveType $leaveType,
        public readonly int $year,
        public readonly ?UserInterface $actor = null,
        public readonly ?float $entitledDays = null,
        public readonly ?float $accruedDays = null,
        public readonly ?float $carriedOverDays = null,
        public readonly ?float $adjustEntitledDays = null,
        public readonly ?string $reason = null,
    ) {
    }
}
