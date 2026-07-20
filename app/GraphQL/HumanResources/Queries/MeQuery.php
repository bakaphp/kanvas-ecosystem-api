<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;

class MeQuery
{
    use ResolvesActingContext;

    public function employee(): ?Employee
    {
        $context = $this->actingContext();

        return new EmployeeIdentityResolver()->fromUser($context->user, $context->company, $context->app);
    }
}
