<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Queries;

use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;

class MeQuery
{
    /**
     * The acting user's own HR employee record — powers self-service ("how many vacation days do I have?").
     */
    public function employee(): ?Employee
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        return new EmployeeIdentityResolver()->fromUser($user, $company, $app);
    }
}
