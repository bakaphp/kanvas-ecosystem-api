<?php
declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Auth\Services\UserManagement as UserManagementService;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Update
{
    /**
     * Update user information.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return UsersInvite
     */
    public function updateUser(
        mixed $rootValue,
        array $request,
        GraphQLContext $context = null,
        ResolveInfo $resolveInfo
    ) {

        $userManagement = new UserManagementService(Users::getById(auth()->user()->id));
        $user = $userManagement->update($request['data']);

        return $user;
    }
}
