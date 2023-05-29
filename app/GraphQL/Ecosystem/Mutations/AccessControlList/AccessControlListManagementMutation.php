<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Bouncer;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Users\Repositories\UsersRepository;
use Silber\Bouncer\Database\Role as SilberRole;

class AccessControlListManagementMutation
{
    /**
     * assignRoleToUser.
     */
    public function assignRoleToUser(mixed $rootValue, array $request): bool
    {
        $role = Role::where('name', $request['role'])->firstOrFail();

        $assign = new AssignAction(
            UsersRepository::getById(
                $request['userId'],
                auth()->user()->getCurrentCompany()->getId()
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
        $role = Role::where('name', $request['role'])->firstOrFail();

        $user = UsersRepository::getById(
            $request['userId'],
            auth()->user()->getCurrentCompany()->getId()
        );
        $user->retract($role->name);

        return true;
    }

    /**
     * givePermissionToUser.
     */
    public function givePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById(
            $request['userId'],
            auth()->user()->getCurrentCompany()->getId()
        );
        Bouncer::allow($user)->to($request['permission']);

        return true;
    }

    /**
     * removePermissionToUser.
     */
    public function removePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById(
            $request['userId'],
            auth()->user()->getCurrentCompany()->getId()
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
