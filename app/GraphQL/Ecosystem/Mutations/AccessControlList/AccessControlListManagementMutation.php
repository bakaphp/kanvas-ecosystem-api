<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Bouncer;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\Companies\Models\Companies;
use Silber\Bouncer\Database\Role as SilberRole;

class AccessControlListManagementMutation
{
    /**
     * assignRoleToUser.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function assignRoleToUser(mixed $rootValue, array $request): bool
    {
        $assign = new AssignAction(
            UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id),
            $request['role']
        );
        $assign->execute();
        return true;
    }

    /**
     * removeRoleFromUser.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function removeRoleFromUser(mixed $rootValue, array $request): bool
    {
        $role = $request['role'];
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        $user->retract($role);
        return true;
    }

    /**
     * givePermissionToUser.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function givePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        Bouncer::allow($user)->to($request['permission']);
        return true;
    }

    /**
     * removePermissionToUser.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function removePermissionToUser(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        Bouncer::disallow($user)->to($request['permission']);
        return true;
    }

    /**
     * createRole.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function createRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new CreateRoleAction(
            $request['name'],
            $request['title']
        );
        return $role->execute(Companies::getById(auth()->user()->currentCompanyId()));
    }

    /**
     * updateRole.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return void
     */
    public function updateRole(mixed $rootValue, array $request): SilberRole
    {
        $role = new UpdateRoleAction(
            $request['id'],
            $request['name'],
            $request['title'] ?? null
        );
        return $role->execute(Companies::getById(auth()->user()->currentCompanyId()));
    }
}
