<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Bouncer;
use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\Users\Repositories\UsersRepository;

class UserAccessControlList
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
}
