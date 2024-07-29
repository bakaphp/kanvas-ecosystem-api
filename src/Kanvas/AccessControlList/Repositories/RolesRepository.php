<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Baka\Contracts\AppInterface;
use Bouncer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Silber\Bouncer\Database\Ability;

class RolesRepository
{
    public static function getByMixedParamFromCompany(int|string $param, ?Companies $company = null): Role
    {
        return is_numeric($param) ? RolesRepository::getByIdFromCompany((int) $param) : RolesRepository::getByNameFromCompany($param);
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByNameFromCompany(string $name, ?Companies $company = null, ?AppInterface $app = null): Role
    {
        $app = $app ?? app(Apps::class);

        return Role::where('name', $name)
            ->where('scope', RolesEnums::getScope($app, null))
            ->firstOrFail();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByIdFromCompany(int $id, ?Companies $company = null, ?AppInterface $app = null): Role
    {
        $app = $app ?? app(Apps::class);

        return Role::where('id', $id)
                ->where('scope', RolesEnums::getScope($app, null))
                ->firstOrFail();
    }

    /**
     * getAllRoles.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getAllRoles(?AppInterface $app = null): Collection
    {
        $app = $app ?? app(Apps::class);

        return Role::where('scope', RolesEnums::getScope($app, null))
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get app list of default roles.
     */
    public static function getAppRoles(AppInterface $app): Collection
    {
        return Role::where('scope', RolesEnums::getScope($app))->get();
    }

    public static function getMapAbilityInModules(string $roleName): array
    {
        $roles = Bouncer::role()->where('name', $roleName)->firstOrFail();
        $subQuery = DB::table('permissions')
                    ->where('entity_type', 'roles')
                    ->where('permissions.entity_id', $roles->id)
                    ->select('permissions.*');
        $abilities = Ability::join('abilities_modules', 'abilities.id', '=', 'abilities_modules.abilities_id')
            ->leftJoinSub($subQuery, 'permissions', function ($join) {
                $join->on('abilities.id', '=', 'permissions.ability_id');
            })
            ->orderBy('module_id')
            ->select('abilities.*', 'permissions.entity_id as roleId', 'abilities_modules.module_id as module')
            ->get();

        return self::mapPermissionsToStructure($abilities);
    }

    protected static function mapPermissionsToStructure($permissions)
    {
        $mappedPermissions = [];

        foreach ($permissions as $permission) {
            $module = $permission->module;
            $entityType = $permission->entity_type;
            if (! isset($mappedPermissions[$module])) {
                $mappedPermissions[$module] = [
                    'name' => $module,
                    'entities' => [],
                ];
            }

            if (! isset($mappedPermissions[$module]['entities'][$entityType])) {
                $mappedPermissions[$module]['entities'][$entityType] = [
                    'name' => $entityType,
                    'abilities' => [],
                ];
            }

            $mappedPermissions[$module]['entities'][$entityType]['abilities'][] = [
                'name' => $permission['name'],
                'description' => $permission['title'],
                'entity_id' => $permission['entity_id'],
                'module' => $permission['module'],
            ];
        }
        foreach ($mappedPermissions as &$module) {
            $module['entities'] = array_values($module['entities']);
            foreach ($module['entities'] as &$entity) {
                $entity['abilities'] = array_values($entity['abilities']);
            }
        }

        return $mappedPermissions;
    }
}
