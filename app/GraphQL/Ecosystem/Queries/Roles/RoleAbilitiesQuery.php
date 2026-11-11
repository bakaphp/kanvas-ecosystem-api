<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Users\Repositories\UsersRepository;

class RoleAbilitiesQuery
{
    public function getAllAbilities(mixed $root, array $query): array
    {
        $company = ! empty($query['companyId'])
            ? CompaniesRepository::getById((int) $query['companyId'])
            : auth()->user()->getCurrentCompany();
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
        return RolesRepository::getMapAbilityInModules($request['role']);
    }
}
