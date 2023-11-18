<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
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
        $user = Users::find((int)$req['user_id']);
        $userAssociate = UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $userAssociate->softDelete();
    }

    public function restoreDeletedUser(mixed $root, array $request): bool
    {
        $user = Users::find((int)$request['user_id']);
        $userAssociatedApp = UsersAssociatedApps::where('users_id', $user->getKey())
                            ->where('apps_id', app(Apps::class)->getKey())
                            ->firstOrFail();

        $userAssociatedApp->restoreRecord();

        //@todo if we delete a user , do cascade delete on all the user's data

        return true;
    }
}
