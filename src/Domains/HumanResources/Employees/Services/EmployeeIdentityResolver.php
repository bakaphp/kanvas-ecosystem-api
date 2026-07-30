<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Employees\Models\Employee;

/**
 * Resolves the acting user to its HR Employee record (the "who am I talking to" hot path).
 * Returns null when the user is not an employee of this company — callers fall back gracefully.
 */
class EmployeeIdentityResolver
{
    public function fromUser(
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): ?Employee {
        /** @var Employee|null $employee */
        $employee = Employee::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('users_id', $user->getId())
            ->first();

        return $employee;
    }
}
