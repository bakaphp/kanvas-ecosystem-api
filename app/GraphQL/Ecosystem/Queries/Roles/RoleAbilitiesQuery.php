<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Illuminate\Support\Facades\Redis;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Repositories\UsersRepository;

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

    public function getAllAbilitiesByRoles(mixed $root, array $request): array
    {
        $roles = RolesRepository::getMapAbilityInModules($request['role']);
        if ($map = Redis::get('roles:abilities')) {
            return $map;
        }
        Redis::set('roles:abilities' , $roles);
    }
}
