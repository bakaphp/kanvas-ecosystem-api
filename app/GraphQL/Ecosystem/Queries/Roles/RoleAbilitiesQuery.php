<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Bouncer;
use Illuminate\Support\Facades\DB;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\Database\Ability;

class RoleAbilitiesQuery
{
    public function getAllAbilities(mixed $root, array $query): array
    {
        $abilities = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int)$query['userId']
        )->getAbilities();

        $mapAbilities = $abilities->map(function ($ability) {
            return $ability->name;
        });

        return $mapAbilities->all();
    }

    public function getAllAbilitiesByRoles(mixed $root, array $request)
    {
        $roles = Bouncer::role()->where('name', $request['role'])->firstOrFail();
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

        return $abilities;
    }
}
