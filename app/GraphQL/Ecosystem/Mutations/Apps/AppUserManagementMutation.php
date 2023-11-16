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
    public function updatePassword(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['uuid']);
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, $app);

        return $user->resetPassword($request['password'], $app);
    }

    public function updateEmail(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['uuid']);
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $user->updateEmail($request['email']);
    }

    public function appDeleteUser(mixed $root, array $req): bool
    {
        $user = Users::find($req['users_id']);
        $userAssociate = UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $userAssociate->delete();
    }

    public function restoreDeletedUser(mixed $root, array $request): bool
    {
        $user = Users::find((int)$request['user_id']);
        $userAssociate = UsersRepository::deleteBelongsToThisApp($user, app(Apps::class));
        $userAssociate->restore();

        return true;
    }
}
