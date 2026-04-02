<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\AbilitiesModules;
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

        $scope = RolesEnums::getScope($this->app);
        $appId = $this->app->getId();
        $modulesGranted = [];

        // Pre-load all abilities for this app scope in one query
        $existingAbilities = Bouncer::ability()
            ->whereNotNull('entity_type')
            ->get()
            ->groupBy(fn ($a) => $a->name . '|' . $a->entity_type);

        // Pre-load all abilities_modules for this app in one query
        $existingModules = AbilitiesModules::where('apps_id', $appId)
            ->where('scope', $scope)
            ->get()
            ->keyBy(fn ($am) => $am->abilities_id . '|' . $am->module_id);

        $modulesToInsert = [];

        foreach ($this->permissions as $permission) {
            $modelName = $permission['model_name'];
            $systemModule = SystemModulesRepository::getByModelName($modelName, $this->app);
            $moduleId = ModulesRepositories::getModuleIdByModelName($modelName);

            // Grant module-level permissions if specified
            if ($moduleId !== null && ! in_array($moduleId, $modulesGranted)) {
                if (isset($permission['module_permissions']) && is_array($permission['module_permissions'])) {
                    foreach ($permission['module_permissions'] as $modulePermission) {
                        $compoundPermission = ModulePermission::getPermissionName($moduleId, $modulePermission);
                        Bouncer::allow($this->role->name)->to($compoundPermission);
                    }
                }

                $modulesGranted[] = $moduleId;
            }

            // Grant entity-specific permissions
            foreach ($permission['permission'] as $perm) {
                Bouncer::allow($this->role->name)->to($perm, $modelName);

                // Sync abilities_modules using pre-loaded data
                if ($moduleId !== null) {
                    $key = $perm . '|' . $modelName;
                    $ability = ($existingAbilities[$key] ?? collect())->first()
                        ?? Bouncer::ability()->where('name', $perm)->where('entity_type', $modelName)->first();

                    if ($ability) {
                        $amKey = $ability->id . '|' . $moduleId;
                        if (! $existingModules->has($amKey)) {
                            $modulesToInsert[] = [
                                'abilities_id' => $ability->id,
                                'module_id' => $moduleId,
                                'scope' => $scope,
                                'system_modules_id' => $systemModule->getId(),
                                'apps_id' => $appId,
                                'is_deleted' => 0,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }
            }
        }

        // Batch insert new abilities_modules entries
        if (! empty($modulesToInsert)) {
            AbilitiesModules::insert($modulesToInsert);
        }
    }
}
