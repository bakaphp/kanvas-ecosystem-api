<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Positions\Models\Position;

/**
 * Resolve an HR Position by title / Department by name within the tool's app+company (from
 * HasKanvasContext), returning null when the name is blank or not found.
 */
trait ResolvesPositionAndDepartmentForTool
{
    protected function findPositionByTitle(?string $title): ?Position
    {
        if ($title === null || $title === '') {
            return null;
        }

        /** @var Position|null $position */
        $position = Position::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('title', $title)
            ->first();

        return $position;
    }

    protected function findDepartmentByName(?string $name): ?Department
    {
        if ($name === null || $name === '') {
            return null;
        }

        /** @var Department|null $department */
        $department = Department::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('name', $name)
            ->first();

        return $department;
    }
}
