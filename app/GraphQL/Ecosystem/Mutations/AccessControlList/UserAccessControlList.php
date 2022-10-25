<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use App\GraphQL\Ecosystem\Mutations\AccessControlList\Assign;
use Kanvas\Users\Models\Users;

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
            Users::findOrFail($request['user_id']),
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
