<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Models\ModulePermission;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Silber\Bouncer\Database\Role as SilberRole;

class BulkAllowRoleToPermissionAction
{
    public function __construct(
        protected Apps $app,
        protected SilberRole $role,
        protected array $permissions,
        protected ?SilberRole $template = null
    ) {
    }

    public function execute(): void
    {
        if ($this->template) {
            $permissions = $this->template->query()
                ->join('permissions', 'permissions.entity_id', '=', 'roles.id')
                ->join('abilities', 'ability_id', '=', 'abilities.id')
                ->where('permissions.entity_type', '=', 'roles')
                ->where('permissions.entity_id', '=', $this->template->id)
                ->select('abilities.name', 'abilities.entity_type')
                ->get();
            foreach ($permissions as $permission) {
                Bouncer::allow($this->role->name)->to($permission->name, $permission->entity_type);
            }

            return;
        }

        $modulesGranted = [];

        foreach ($this->permissions as $permission) {
            $modelName = $permission['model_name'];
            $systemModule = SystemModulesRepository::getByModelName($modelName, $this->app);

            // Get the module ID for this model
            $moduleId = ModulesRepositories::getModuleIdByModelName($modelName);

            // Grant module-level permissions if specified (using compound permission names)
            if ($moduleId !== null && ! in_array($moduleId, $modulesGranted)) {
                // Check if module_permissions are provided in the input
                if (isset($permission['module_permissions']) && is_array($permission['module_permissions'])) {
                    foreach ($permission['module_permissions'] as $modulePermission) {
                        // Create compound permission name: e.g., 'view-module-inventory'
                        $compoundPermission = ModulePermission::getPermissionName($moduleId, $modulePermission);
                        Bouncer::allow($this->role->name)->to($compoundPermission);
                    }
                }

                $modulesGranted[] = $moduleId;
            }

            // Grant entity-specific permissions
            foreach ($permission['permission'] as $perm) {
                if (! $systemModule->abilities()->where('name', $perm)->exists()) {
                    continue;
                }
                /*  $ability = $systemModule->abilities()->where('name', $perm)
                             ->firstOrFail(); */
                Bouncer::allow($this->role->name)->to($perm, $modelName);
            }
        }
    }
}
