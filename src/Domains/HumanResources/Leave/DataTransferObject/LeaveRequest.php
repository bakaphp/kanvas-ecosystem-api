<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Leave\Models\LeaveType;
use Spatie\LaravelData\Data;

class LeaveRequest extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Employee $employee,
        public readonly LeaveType $leaveType,
        public readonly string $startDate,
        public readonly string $endDate,
        public readonly ?string $reason = null,
    ) {
    }
}
