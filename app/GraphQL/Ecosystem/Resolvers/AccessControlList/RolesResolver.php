<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Resolvers\AccessControlList;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Users\Repositories\UsersRepository;

class RolesResolver
{
    /**
     * getAllRoles.
     *
     * @return Collection
     */
    public function getAllRoles(): ?Collection
    {
        return RolesRepository::getAllRoles();
    }

    /**
     * hasRole.
     *
     * @param  mixed $_
     * @param  array $request
     *
     * @return bool
     */
    public function hasRole(mixed $_, array $request): bool
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->getCurrentCompany()->id);
        return $user->isAn($request['role']);
    }
}
