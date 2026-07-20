<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Queries;

use App\GraphQL\HumanResources\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;

class MeQuery
{
    use ResolvesActingContext;

    /**
     * The acting user's own HR employee record — powers self-service ("how many vacation days do I have?").
     */
    public function employee(): ?Employee
    {
        [$user, $app, $company] = $this->actingContext();

        return new EmployeeIdentityResolver()->fromUser($user, $company, $app);
    }
}
