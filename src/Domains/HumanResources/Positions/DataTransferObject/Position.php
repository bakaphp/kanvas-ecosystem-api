<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Positions\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Departments\Models\Department;
use Spatie\LaravelData\Data;

class Position extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        public readonly string $title,
        public readonly ?Department $department = null,
        public readonly ?string $level = null,
        public readonly ?string $description = null,
        public readonly bool $isActive = true,
    ) {
    }
}
