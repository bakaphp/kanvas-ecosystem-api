<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Compensation\Models\PayBand;
use Kanvas\HumanResources\Employees\Models\Employee;
use Spatie\LaravelData\Data;

class EmployeeCompensation extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Employee $employee,
        public readonly float $amount,
        public readonly string $effectiveFrom,
        public readonly string $currency = 'USD',
        public readonly string $payFrequency = 'annual',
        public readonly ?PayBand $payBand = null,
        public readonly ?string $changeReason = null,
    ) {
    }
}
