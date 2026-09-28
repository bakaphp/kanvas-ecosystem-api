<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Actions;

use Bouncer;
use Illuminate\Support\Carbon;
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

        $this->createModulePermissions();

        $abilitiesByModule = ModulesRepositories::getAbilitiesByModule();

        $systemModules = SystemModulesRepository::getByModelNames(
            array_merge(...array_map('array_keys', array_values($abilitiesByModule))),
            $this->app
        );

        $grants = [];
        foreach ($abilitiesByModule as $module => $subModule) {
            foreach ($subModule as $model => $abilities) {
                foreach ($abilities as $ability) {
                    $grants[] = [$module, $model, $ability];
                }
            }
        }

        $resolved = new ResolveAbilitiesAction()->execute(
            array_map(static fn (array $grant): array => [$grant[2], $grant[1]], $grants)
        );

        $wanted = [];
        foreach ($grants as [$module, $model, $ability]) {
            $systemModuleId = $systemModules[$model]->getId();
            $abilityId = $resolved[ResolveAbilitiesAction::key($ability, $model)]->getKey();

            $wanted[$module . '-' . $systemModuleId . '-' . $abilityId] = [
                'module_id' => $module,
                'apps_id' => $this->app->getId(),
                'scope' => $scope,
                'system_modules_id' => $systemModuleId,
                'abilities_id' => $abilityId,
            ];
        }

        $this->syncAbilitiesModules($wanted, $scope);
    }

    /**
     * One select plus at most one insert and one update, in place of an updateOrCreate per ability.
     *
     * @param array<string, array<string, mixed>> $wanted
     */
    protected function syncAbilitiesModules(array $wanted, string $scope): void
    {
        if (empty($wanted)) {
            return;
        }

        $existing = AbilitiesModules::query()
            ->where('apps_id', $this->app->getId())
            ->where('scope', $scope)
            ->whereIn('abilities_id', array_column($wanted, 'abilities_id'))
            ->get()
            ->keyBy(fn (AbilitiesModules $row): string => $row->module_id . '-' . $row->system_modules_id . '-' . $row->abilities_id);

        $missing = array_diff_key($wanted, $existing->all());
        $now = Carbon::now();

        if (! empty($missing)) {
            AbilitiesModules::query()->insert(array_map(
                static fn (array $row): array => $row + [
                    'is_deleted' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                array_values($missing)
            ));
        }

        // A module/ability pairing that is wanted again must come back even if it was soft deleted.
        $revive = $existing
            ->only(array_keys($wanted))
            ->filter(fn (AbilitiesModules $row): bool => (int) $row->is_deleted !== 0)
            ->pluck('id')
            ->all();

        if (! empty($revive)) {
            AbilitiesModules::query()->whereIn('id', $revive)->update(['is_deleted' => 0]);
        }
    }

    /**
     * Create module-level permissions.
     * Creates compound permission names like 'view-module-inventory', 'manage-module-crm'
     */
    protected function createModulePermissions(): void
    {
        $pairs = [];

        foreach (ModulesRepositories::getModulePermissions() as $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                // entity_type is null because these are simple permissions without entity
                $pairs[] = [$permissionName, null, ucwords(str_replace('-', ' ', $permissionName))];
            }
        }

        new ResolveAbilitiesAction()->execute($pairs);
    }
}
