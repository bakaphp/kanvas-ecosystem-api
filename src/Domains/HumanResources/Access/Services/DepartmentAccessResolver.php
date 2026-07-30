<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Access\Services;

use Kanvas\HumanResources\Access\Enums\AccessLevelEnum;
use Kanvas\HumanResources\Departments\Models\Department;

/**
 * Resolves a department's effective module-access map, inheriting grants from ancestor
 * departments so a sub-team gets its parent function's access (most-specific wins).
 */
class DepartmentAccessResolver
{
    /**
     * @return array<string, AccessLevelEnum> module_slug => level
     */
    public function forDepartment(Department $department): array
    {
        $map = [];

        // Ancestors first, then self — so the most-specific (self) grant overrides.
        $chain = $department->ancestors()->get()->reverse()->push($department);

        foreach ($chain as $node) {
            foreach ($node->moduleAccess()->where('is_deleted', 0)->get() as $access) {
                $map[$access->module_slug] = AccessLevelEnum::from($access->level);
            }
        }

        return $map;
    }

    public function levelFor(Department $department, string $moduleSlug): AccessLevelEnum
    {
        return $this->forDepartment($department)[$moduleSlug] ?? AccessLevelEnum::NONE;
    }
}
