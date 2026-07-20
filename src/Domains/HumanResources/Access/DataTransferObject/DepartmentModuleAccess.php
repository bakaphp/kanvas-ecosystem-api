<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Access\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Access\Enums\AccessLevelEnum;
use Kanvas\HumanResources\Departments\Models\Department;
use Spatie\LaravelData\Data;

class DepartmentModuleAccess extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly Department $department,
        public readonly string $moduleSlug,
        public readonly AccessLevelEnum $level = AccessLevelEnum::NONE,
    ) {
    }
}
