<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Services\UserNotificationService;

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

    public function createUser(mixed $rootValue, array $request): Users
    {
        $user = auth()->user();
        $branch = $user->getCurrentBranch();
        $app = app(Apps::class);

        UsersRepository::belongsToThisApp($user, app(Apps::class));

        if (! isset($request['data']['password'])) {
            $request['data']['password'] = Str::random(15);
        }

        $assignCurrentUserBranch = $app->get(AppSettingsEnums::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY->getValue());
        /** @var CompaniesBranches|null */
        $assignBranch = $assignCurrentUserBranch ? $branch : null;

        $data = RegisterInput::fromArray($request['data'], $assignBranch);
        $user = (new CreateUserAction($data))->execute();

        UserNotificationService::sendCreateUserEmail($app, $branch, $user, $request);

        return $user;
    }

    public function appDeleteUser(mixed $root, array $req): bool
    {
        $user = Users::find((int)$req['user_id']);
        $userAssociate = UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $userAssociate->softDelete();
    }

    public function appDeActivateUser(mixed $root, array $req): bool
    {
        $user = Users::find((int)$req['user_id']);
        $userAssociate = UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $userAssociate->deActive();
    }

    public function appActivateUser(mixed $root, array $req): bool
    {
        $user = Users::find((int)$req['user_id']);
        $userAssociate = UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $userAssociate->active();
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
