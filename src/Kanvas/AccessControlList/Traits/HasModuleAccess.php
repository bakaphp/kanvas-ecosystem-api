<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Traits;

use Kanvas\AccessControlList\Models\ModulePermission;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Exceptions\AuthorizationException;

trait HasModuleAccess
{
    /**
     * Check if user can access a specific module.
     * Uses compound permission name: e.g., 'view-module-inventory'
     */
    public function canAccessModule(int $moduleId): bool
    {
        $permissionName = ModulePermission::getPermissionName($moduleId, 'view');

        return $this->can($permissionName);
    }

    /**
     * Check if user can manage a specific module.
     * Uses compound permission name: e.g., 'manage-module-inventory'
     */
    public function canManageModule(int $moduleId): bool
    {
        $permissionName = ModulePermission::getPermissionName($moduleId, 'manage');

        return $this->can($permissionName);
    }

    /**
     * Check if user can access the module that contains the given model.
     *
     * @throws AuthorizationException
     */
    public function ensureCanAccessModuleForModel(string $modelName): void
    {
        $moduleId = ModulesRepositories::getModuleIdByModelName($modelName);

        if ($moduleId === null) {
            throw new AuthorizationException('Model not found in any module');
        }

        if (! $this->canAccessModule($moduleId)) {
            throw new AuthorizationException('You do not have access to this module');
        }
    }

    /**
     * Check if user can access entity within a module.
     * This checks both module access and entity permission.
     */
    public function canAccessEntity(string $modelName, string $permission): bool
    {
        $moduleId = ModulesRepositories::getModuleIdByModelName($modelName);

        if ($moduleId === null) {
            return false;
        }

        // First check module access
        if (! $this->canAccessModule($moduleId)) {
            return false;
        }

        // Then check entity permission
        return $this->can($permission, $modelName);
    }
}
