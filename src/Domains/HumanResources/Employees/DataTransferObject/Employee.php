<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\HumanResources\Employees\Enums\EmploymentTypeEnum;
use Kanvas\HumanResources\Employees\Models\Employee as EmployeeModel;
use Kanvas\HumanResources\Positions\Models\Position;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Employee extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly Users $loginUser,
        public readonly People $people,
        public readonly Position $position,
        public readonly string $hiredAt,
        public readonly EmploymentTypeEnum $employmentType = EmploymentTypeEnum::EMPLOYEE,
        public readonly EmployeeStatusEnum $status = EmployeeStatusEnum::ONBOARDING,
        public readonly ?Department $department = null,
        public readonly ?EmployeeModel $manager = null,
        public readonly ?string $employeeNumber = null,
        public readonly ?string $description = null,
        public readonly ?string $homeEntity = null,
        public readonly ?bool $endIsVoluntary = null,
    ) {
    }
}
