<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Access;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Access\Actions\SetDepartmentModuleAccessAction;
use Kanvas\HumanResources\Access\DataTransferObject\DepartmentModuleAccess as DepartmentModuleAccessData;
use Kanvas\HumanResources\Access\Enums\AccessLevelEnum;
use Kanvas\HumanResources\Access\Models\DepartmentModuleAccess;
use Kanvas\HumanResources\Departments\Models\Department;

class AccessMutation
{
    use ResolvesActingContext;

    public function set(mixed $rootValue, array $request): DepartmentModuleAccess
    {
        $context = $this->actingContext();
        $input = $request['input'];

        /** @var Department $department */
        $department = Department::getByIdFromCompanyApp((int) $input['department_id'], $context->company, $context->app);

        return new SetDepartmentModuleAccessAction(
            new DepartmentModuleAccessData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                department: $department,
                moduleSlug: $input['module_slug'],
                level: AccessLevelEnum::from($input['level']),
            ),
        )->execute();
    }
}
