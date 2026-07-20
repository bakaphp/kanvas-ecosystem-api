<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Exceptions\HumanResourcesException;
use Kanvas\Users\Models\Users;

class LinkEmployeeToUserAction
{
    public function __construct(
        protected readonly Employee $employee,
        protected readonly Users $user,
    ) {
    }

    public function execute(): Employee
    {
        return DB::connection('hr')->transaction(function () {
            $this->assertUserNotAlreadyEmployee();

            $this->employee->users_id = $this->user->getId();
            $this->employee->saveOrFail();

            return $this->employee;
        });
    }

    private function assertUserNotAlreadyEmployee(): void
    {
        $alreadyLinked = Employee::query()
            ->where('apps_id', $this->employee->apps_id)
            ->where('companies_id', $this->employee->companies_id)
            ->where('users_id', $this->user->getId())
            ->where('id', '!=', $this->employee->getId())
            ->where('is_deleted', 0)
            ->exists();

        if ($alreadyLinked) {
            throw new HumanResourcesException('This user is already linked to another employee in this company.');
        }
    }
}
