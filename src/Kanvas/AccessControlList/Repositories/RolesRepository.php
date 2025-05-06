<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Baka\Contracts\AppInterface;
use Bouncer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Silber\Bouncer\Database\Ability;

class RolesRepository
{
    public static function getByMixedParamFromCompany(int|string $param, ?Companies $company = null, ?AppInterface $app = null): Role
    {
        return is_numeric($param) ? RolesRepository::getByIdFromCompany((int) $param, $company, $app) : RolesRepository::getByNameFromCompany($param, $company, $app);
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
            ->join('kanvas_modules as modules', 'modules.id', '=', 'abilities_modules.module_id')
            ->orderBy('modules.id')
            ->select('abilities.*', 'abilities_modules.system_modules_id', 'permissions.entity_id as roleId', 'modules.id', 'modules.name')
            ->get();
        $roles =  self::mapPermissionsToStructure($abilities);
        return $roles;
    }

    protected static function mapPermissionsToStructure($permissions): array
    {
        $modules = [];
        foreach ($permissions as $permission) {
            $ability = [
                'title' => $permission->title,
                'roleId' => (bool) $permission->roleId
            ];
            if (! isset($modules[$permission['name']])) {
                $modules[$permission['name']] = [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'systemModules' => []
                ];
            }
            $systemModule = $permission->entity_type;
            if (empty($modules[$permission['name']]['systemModules'])) {
                $modules[$permission['name']]['systemModules'][] = [
                    'id' => $permission->system_modules_id,
                    'name' => $systemModule,
                    'abilities' => [$ability]
                ];
                continue;
            }
            $found = false;
            foreach ($modules[$permission['name']]['systemModules'] as $key => $systemModules) {
                if ($systemModules['name'] == $systemModule) {
                    $systemModules['abilities'][] = $ability;
                    $modules[$permission['name']]['systemModules'][$key] = $systemModules;
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                $modules[$permission['name']]['systemModules'][] = [
                    'id' => $permission->system_modules_id,
                    'name' => $systemModule,
                    'abilities' => [$ability]
                ];
            }
        }
        return $modules;
    }
}
