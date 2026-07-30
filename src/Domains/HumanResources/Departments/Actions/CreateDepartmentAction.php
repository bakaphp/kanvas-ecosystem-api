<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Departments\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Departments\DataTransferObject\Department as DepartmentData;
use Kanvas\HumanResources\Departments\Models\Department;

class CreateDepartmentAction
{
    public function __construct(
        protected readonly DepartmentData $data,
    ) {
    }

    public function execute(): Department
    {
        return DB::connection('hr')->transaction(function () {
            $department = new Department();
            $department->apps_id = $this->data->app->getId();
            $department->companies_id = $this->data->company->getId();
            $department->users_id = $this->data->user->getId();
            $department->companies_branches_id = $this->data->companiesBranchesId;
            $department->parent_id = $this->data->parent?->getId();
            $department->name = $this->data->name;
            $department->code = $this->data->code;
            $department->outcome_line = $this->data->outcomeLine;
            $department->description = $this->data->description;
            $department->saveOrFail();

            return $department;
        });
    }
}
