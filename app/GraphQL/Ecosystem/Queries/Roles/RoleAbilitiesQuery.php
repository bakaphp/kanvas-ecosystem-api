<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Bouncer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\Database\Ability;
use Kanvas\Companies\Repositories\CompaniesRepository;

class RoleAbilitiesQuery
{
    public function getAllAbilities(mixed $root, array $query): array
    {
        $company = $query['companyId'] ? CompaniesRepository::getById((int)$query['companyId']) : auth()->user()->getCurrentCompany();
        $abilities = UsersRepository::getUserOfCompanyById(
            $company,
            (int)$query['userId']
        )->getAbilities();

        $mapAbilities = $abilities->map(function ($ability) {
            return $ability->name;
        });

        return $mapAbilities->all();
    }

    public function getAllAbilitiesByRoles(mixed $root, array $request): Collection
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
