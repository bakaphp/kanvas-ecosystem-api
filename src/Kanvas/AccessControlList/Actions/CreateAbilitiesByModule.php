<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\AbilitiesModules;
use Kanvas\AccessControlList\Models\Ability;
use Kanvas\AccessControlList\Templates\ModulesRepositories;
use Kanvas\Apps\Models\Apps;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

class CreateAbilitiesByModule
{
    public function __construct(protected ?Apps $app = null)
    {
        $this->app = $app ?? app(Apps::class);
    }

    /**
     * Create abilities by module.
     *
     * @return void
     */
    public function execute()
    {
        $scope = RolesEnums::getScope($this->app);
        Bouncer::scope()->to($scope);
        Bouncer::useAbilityModel(Ability::class);

        // Create module-level permissions first
        $this->createModulePermissions($scope);

        // Then create entity-specific permissions
        foreach (ModulesRepositories::getAbilitiesByModule() as $module => $subModule) {
            foreach ($subModule as $model => $abilities) {
                $systemModule = SystemModulesRepository::getByModelName($model, $this->app);
                foreach ($abilities as $ability) {
                    $ability = Bouncer::ability()->firstOrCreate([
                        'name' => $ability,
                        'title' => ucfirst($ability),
                        'entity_type' => $model,
                    ]);
                    AbilitiesModules::firstOrCreate(
                        [
                            'system_modules_id' => $systemModule->getId(),
                            'abilities_id' => $ability->id,
                            'scope' => $scope,
                            'module_id' => $module,
                            'apps_id' => $this->app->getId(),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Create module-level permissions.
     * Creates compound permission names like 'view-module-inventory', 'manage-module-crm'
     */
    protected function createModulePermissions(int $scope): void
    {
        foreach (ModulesRepositories::getModulePermissions() as $moduleId => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                // Create ability with compound name (e.g., 'view-module-inventory')
                // entity_type is null because these are simple permissions without entity
                Bouncer::ability()->firstOrCreate([
                    'name' => $permissionName,
                    'title' => ucwords(str_replace('-', ' ', $permissionName)),
                ]);
            }
        }
    }
}
