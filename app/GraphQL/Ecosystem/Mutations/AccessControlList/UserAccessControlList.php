<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use App\GraphQL\Ecosystem\Mutations\AccessControlList\Assign;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class UserAccessControlList
{
    /**
     * assignRoleToUser
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return void
     */
    public function assignRoleToUser($rootValue, array $request): bool
    {
        $assign = new Assign(
            UsersRepository::getById($request['user_id']),
            $request['role']
        );
        $assign->execute();
        return true;
    }

    /**
     * removeRoleFromUser
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return void
     */
    public function removeRoleFromUser($rootValue, array $request): bool
    {
        $user = $request['user'];
        $role = $request['role'];
        $user->retract($role);
        return true;
    }
}
