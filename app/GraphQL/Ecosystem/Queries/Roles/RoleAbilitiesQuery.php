<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UsersRepository;

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
        $scope = RolesEnums::getScope(app(Apps::class));

        return Role::where('name', $request['role'])
        ->where('scope', $scope)->first()->abilities;
    }
}
