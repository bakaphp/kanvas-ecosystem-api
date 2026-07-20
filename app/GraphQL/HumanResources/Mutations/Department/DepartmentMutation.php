<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Department;

use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Departments\Actions\CreateDepartmentAction;
use Kanvas\HumanResources\Departments\Actions\UpdateDepartmentAction;
use Kanvas\HumanResources\Departments\DataTransferObject\Department as DepartmentData;
use Kanvas\HumanResources\Departments\Models\Department;

class DepartmentMutation
{
    public function create(mixed $rootValue, array $request): Department
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Department|null $parent */
        $parent = isset($input['parent_id'])
            ? Department::getByIdFromCompanyApp((int) $input['parent_id'], $company, $app)
            : null;

        return new CreateDepartmentAction(
            new DepartmentData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'],
                code: $input['code'] ?? null,
                outcomeLine: $input['outcome_line'] ?? null,
                description: $input['description'] ?? null,
                parent: $parent,
                companiesBranchesId: isset($input['companies_branches_id']) ? (int) $input['companies_branches_id'] : null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Department
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Department $department */
        $department = Department::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        $parent = isset($input['parent_id'])
            ? Department::getByIdFromCompanyApp((int) $input['parent_id'], $company, $app)
            : $department->parent;

        return new UpdateDepartmentAction(
            $department,
            new DepartmentData(
                app: $app,
                company: $company,
                user: $user,
                name: $input['name'] ?? $department->name,
                code: $input['code'] ?? $department->code,
                outcomeLine: $input['outcome_line'] ?? $department->outcome_line,
                description: $input['description'] ?? $department->description,
                parent: $parent,
                companiesBranchesId: $department->companies_branches_id,
            ),
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $department = Department::getByIdFromCompanyApp((int) $request['id'], $company, $app);

        return $department->softDelete();
    }
}
