<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Access;

use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Access\Actions\SetDepartmentModuleAccessAction;
use Kanvas\HumanResources\Access\DataTransferObject\DepartmentModuleAccess as DepartmentModuleAccessData;
use Kanvas\HumanResources\Access\Enums\AccessLevelEnum;
use Kanvas\HumanResources\Access\Models\DepartmentModuleAccess;
use Kanvas\HumanResources\Departments\Models\Department;

class AccessMutation
{
    public function set(mixed $rootValue, array $request): DepartmentModuleAccess
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Department $department */
        $department = Department::getByIdFromCompanyApp((int) $input['department_id'], $company, $app);

        return new SetDepartmentModuleAccessAction(
            new DepartmentModuleAccessData(
                app: $app,
                company: $company,
                user: $user,
                department: $department,
                moduleSlug: $input['module_slug'],
                level: AccessLevelEnum::from($input['level']),
            ),
        )->execute();
    }
}
