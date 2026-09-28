<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
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

    /**
     * Batch variant of fromUser: resolve many users to their Employee records in one query,
     * keyed by users_id, with position + department eager-loaded for display.
     *
     * @param array<int, int> $userIds
     *
     * @return Collection<int, Employee>
     */
    public function fromUsers(array $userIds, CompanyInterface $company, AppInterface $app): Collection
    {
        if ($userIds === []) {
            return new Collection();
        }

        return Employee::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->whereIn('users_id', $userIds)
            ->with(['position', 'department'])
            ->get()
            ->keyBy('users_id');
    }
}
