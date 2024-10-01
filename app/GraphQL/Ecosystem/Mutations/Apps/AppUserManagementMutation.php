<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Apps;

use Baka\Support\Str;
use Baka\Validations\PasswordValidation;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\DataTransferObject\UpdateUser;
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
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, $app);

        return $user->updateEmail($request['email'], $app);
    }

    public function updateUser(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['user_uuid']);
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, $app);
        $dto = UpdateUser::from($request['input']);
        $profile = $user->getAppProfile($app);

        return $profile->updateOrFail($dto->toArray());
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

        $adminUserRegistrationAssignCurrentCompany = $app->get(AppSettingsEnums::ADMIN_USER_REGISTRATION_ASSIGN_CURRENT_COMPANY->getValue());
        $createCompany = $request['data']['create_company'] ?? false;
        $assignCurrentUserBranch = $adminUserRegistrationAssignCurrentCompany ?? ! $createCompany;
        $assignBranch = $assignCurrentUserBranch ? $branch : null;

        //validate
        PasswordValidation::validateArray($request['data'], $app);

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

    public function appResetUserPassword(mixed $root, array $request): bool
    {
        $user = Users::getByUuid($request['user_id']);
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, $app);

        return $user->resetPassword($request['password'], $app);
    }

    public function appUpdateUserDisplayname(mixed $root, array $request): bool
    {
        $user = Users::getById($request['user_id']);
        $app = app(Apps::class);
        UsersRepository::belongsToThisApp($user, $app);

        return $user->updateDisplayName($request['displayname'], $app);
    }
}
