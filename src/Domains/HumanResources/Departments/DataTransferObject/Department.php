<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Departments\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Departments\Models\Department as DepartmentModel;
use Spatie\LaravelData\Data;

class Department extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $code = null,
        public readonly ?string $outcomeLine = null,
        public readonly ?string $description = null,
        public readonly ?DepartmentModel $parent = null,
        public readonly ?int $companiesBranchesId = null,
    ) {
    }
}
