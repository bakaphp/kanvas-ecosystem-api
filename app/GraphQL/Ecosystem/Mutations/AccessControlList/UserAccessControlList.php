<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\AccessControlList;

use Kanvas\AccessControlList\Actions\AssignAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\AccessControlList\Actions\AllowAction;
use Bouncer;
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
        $assign = new AssignAction(
            UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id),
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
        $role = $request['role'];
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        $user->retract($role);
        return true;
    }
    
    /**
     * givePermissionToUser
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return bool
     */
    public function givePermissionToUser($rootValue, array $request): bool 
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        Bouncer::allow($user)->to($request['permission']);
        return true;
    }  
    
    /**
     * removePermissionToUser
     *
     * @param  mixed $rootValue
     * @param  array $request
     * @return bool
     */
    public function removePermissionToUser($rootValue, array $request): bool 
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        Bouncer::disallow($user)->to($request['permission']);
        return true;
    }
}
