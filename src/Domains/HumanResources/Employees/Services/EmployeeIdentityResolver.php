<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Users\Models\Users;

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
     * Users and People live on other connections than HR, so each is its own query, never a whereHas.
     *
     * @return list<string> lower-cased, de-duplicated
     */
    public function emails(CompanyInterface $company, AppInterface $app): array
    {
        $employees = Employee::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('status', '!=', EmployeeStatusEnum::DEPARTED->value)
            ->toBase()
            ->get(['users_id', 'people_id']);

        $userIds = $employees->pluck('users_id')->filter()->unique()->values()->all();
        $peopleIds = $employees->pluck('people_id')->filter()->unique()->values()->all();

        $emails = [
            ...($userIds === [] ? [] : Users::query()->whereIn('id', $userIds)->pluck('email')->all()),
            ...($peopleIds === [] ? [] : Contact::query()
                ->whereIn('peoples_id', $peopleIds)
                ->whereIn('contacts_types_id', Contact::EMAIL_TYPES)
                ->where('is_deleted', 0)
                ->pluck('value')
                ->all()),
        ];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $email): string => strtolower(trim((string) $email)),
            $emails
        ))));
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
