<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Access\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Access\DataTransferObject\DepartmentModuleAccess as DepartmentModuleAccessData;
use Kanvas\HumanResources\Access\Models\DepartmentModuleAccess;

class SetDepartmentModuleAccessAction
{
    public function __construct(
        protected readonly DepartmentModuleAccessData $data,
    ) {
    }

    public function execute(): DepartmentModuleAccess
    {
        return DB::connection('hr')->transaction(function () {
            $access = DepartmentModuleAccess::query()
                ->where('apps_id', $this->data->app->getId())
                ->where('companies_id', $this->data->company->getId())
                ->where('department_id', $this->data->department->getId())
                ->where('module_slug', $this->data->moduleSlug)
                ->where('is_deleted', 0)
                ->first() ?? new DepartmentModuleAccess();

            $access->apps_id = $this->data->app->getId();
            $access->companies_id = $this->data->company->getId();
            $access->users_id = $this->data->user->getId();
            $access->department_id = $this->data->department->getId();
            $access->module_slug = $this->data->moduleSlug;
            $access->level = $this->data->level->value;
            $access->saveOrFail();

            return $access;
        });
    }
}
