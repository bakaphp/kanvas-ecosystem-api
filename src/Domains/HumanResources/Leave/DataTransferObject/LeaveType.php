<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Leave\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Leave\Enums\AccrualMethodEnum;
use Spatie\LaravelData\Data;

class LeaveType extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly bool $isPaid = true,
        public readonly AccrualMethodEnum $accrualMethod = AccrualMethodEnum::ANNUAL_ALLOTMENT,
        public readonly ?float $defaultAnnualDays = null,
        public readonly ?float $carryoverMaxDays = null,
        public readonly bool $requiresApproval = true,
        public readonly ?string $color = null,
        public readonly bool $isActive = true,
    ) {
    }
}
