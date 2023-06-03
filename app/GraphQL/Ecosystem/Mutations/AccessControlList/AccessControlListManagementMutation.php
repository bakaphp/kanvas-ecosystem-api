<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Baka\Support\Str;
use Bouncer;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\Database\Role as SilberRole;

class AccessControlListManagementMutation
{
    /**
     * assignRoleToUser.
     */
    public function assignRoleToUser(mixed $rootValue, array $request): bool
    {
        $role = RolesRepository::getByMixedParamFromCompany($request['role']);

        $assign = new AssignAction(
            $user = UsersRepository::getUserOfCompanyById(
                auth()->user()->getCurrentCompany(),
                (int) $request['userId']
            ),
            $role
        );
        $assign->execute();

        return true;
    }

    /**
     * removeRoleFromUser.
     */
    public function removeRoleFromUser(mixed $rootValue, array $request): bool
    {
        $role = RolesRepository::getByMixedParamFromCompany($request['role']);

        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['userId']
        );
        $user->retract($role->name);

        return true;
    }

    /**
     * givePermissionToUser.
     */
    public function givePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['userId']
        );
        Bouncer::allow($user)->to(Str::slug($request['permission']));

        return true;
    }

    /**
     * removePermissionToUser.
     */
    public function removePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['userId']
        );
        Bouncer::disallow($user)->to($request['permission']);

        return true;
    }

    /**
     * createRole.
     */
    public function createRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new CreateRoleAction(
            $request['name'],
            $request['title']
        );

        return $role->execute(auth()->user()->getCurrentCompany());
    }

    /**
     * updateRole.
     */
    public function updateRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new UpdateRoleAction(
            $request['id'],
            $request['name'],
            $request['title'] ?? null
        );

        return $role->execute(auth()->user()->getCurrentCompany());
    }
}
