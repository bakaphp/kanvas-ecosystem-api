<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Departments\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\HumanResources\Departments\DataTransferObject\Department as DepartmentData;
use Kanvas\HumanResources\Departments\Models\Department;

class UpdateDepartmentAction
{
    public function __construct(
        protected readonly Department $department,
        protected readonly DepartmentData $data,
    ) {
    }

    public function execute(): Department
    {
        return DB::connection('hr')->transaction(function () {
            $this->department->name = $this->data->name;
            $this->department->code = $this->data->code;
            $this->department->outcome_line = $this->data->outcomeLine;
            $this->department->description = $this->data->description;
            $this->department->parent_id = $this->data->parent?->getId();
            $this->department->companies_branches_id = $this->data->companiesBranchesId;
            $this->department->saveOrFail();

            return $this->department;
        });
    }
}
