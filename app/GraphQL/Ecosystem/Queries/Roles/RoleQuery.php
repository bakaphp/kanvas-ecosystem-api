<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Users\Repositories\UsersRepository;

class RoleQuery
{
    /**
     * getAllRoles.
     */
    public function getAllRoles(): ?Collection
    {
        return RolesRepository::getAllRoles();
    }

    /**
     * hasRole.
     */
    public function hasRole(mixed $_, array $request): bool
    {
        $role = RolesRepository::getByMixedParamFromCompany($request['role']);

        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            $request['userId']
        );

        return $user->isAn($role->name);
    }
}
