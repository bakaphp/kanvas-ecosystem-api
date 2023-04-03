<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class AppUserManagementMutation
{
    /**
     * userUpdatePassword.
     */
    public function userUpdatePassword(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['uuid']);
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $user->resetPassword($request['password']);
    }
}
