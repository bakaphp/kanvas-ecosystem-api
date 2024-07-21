<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Abilities;
use Kanvas\AccessControlList\Models\AbilitiesModules;
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
        Bouncer::useAbilityModel(Abilities::class);

        foreach (ModulesRepositories::getAbilitiesByModule() as $module => $subModule) {
            foreach ($subModule as $model => $abilities) {
                $systemModule = SystemModulesRepository::getByModelName($model);
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
}
