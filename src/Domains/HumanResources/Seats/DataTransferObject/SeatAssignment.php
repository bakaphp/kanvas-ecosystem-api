<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Seats\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Models\Employee;
use Spatie\LaravelData\Data;

class SeatAssignment extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Employee $employee,
        public readonly Department $department,
        public readonly int $allocationPct = 100,
        public readonly bool $isPrimary = true,
        public readonly ?string $effectiveFrom = null,
    ) {
    }
}
