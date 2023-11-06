<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Baka\Support\Str;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\CreateUserAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Auth\Services\UserManagement as UserManagementService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Notifications\Templates\ChangeEmailUserLogged;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\Actions\ProcessInviteAction;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Kanvas\Users\Repositories\UsersRepository;

class UserManagementMutation
{
    /**
     * changePassword.
     */
    public function changePassword(mixed $root, array $req): bool
    {
        $user = UsersRepository::getByEmail(AuthFacade::user()->email);
        $user->changePassword((string) $req['current_password'], (string) $req['new_password'], app(Apps::class));
        $user->notify(new ChangePasswordUserLogged($user, ['company' => $user->getCurrentCompany()]));

        return true;
    }

    /**
     * Update user information.
     */
    public function updateUser(mixed $rootValue, array $request): Users
    {
        $user = auth()->user();
        $userId = $user->isAppOwner() && (int) $request['id'] > 0 ? $request['id'] : $user->getId();
        $userToEdit = UsersRepository::getUserOfCompanyById($user->getCurrentCompany(), (int) $userId);

        $userManagement = new UserManagementService($userToEdit);
        $user = $userManagement->update($request['data']);

        return $user;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     */
    public function insertInvite($rootValue, array $request): UsersInvite
    {
        $request = $request['input'];
        $company = auth()->user()->getCurrentCompany();
        $invite = new CreateInviteAction(
            new InviteDto(
                $request['companies_branches_id'] ?? auth()->user()->getCurrentBranch()->getId(),
                $request['role_id'] ?? RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company)->id,
                $request['email'],
                $request['firstname'] ?? null,
                $request['lastname'] ?? null,
                $request['description'] ?? null,
                $request['email_template'] ?? null,
            ),
            auth()->user()
        );

        return $invite->execute();
    }

    /**
     * deleteInvite.
     *
     * @param  mixed $rootValue
     */
    public function deleteInvite($rootValue, array $request): bool
    {
        $invite = UsersInviteRepository::getById(
            (int) $request['id'],
            auth()->user()->getCurrentCompany()
        );

        $invite->softDelete();

        return true;
    }

    /**
     * processInvite.
     *
     * @param  mixed $rootValue
     */
    public function getInvite($rootValue, array $request): UsersInvite
    {
        //$action = new ProcessInviteAction($request['hash'], $request['password']);
        return UsersInviteRepository::getByHash($request['hash']);
    }

    /**
     * Process User invite.
     *
     * @param  mixed $rootValue
     */
    public function process($rootValue, array $request): Users
    {
        $action = new ProcessInviteAction(
            CompleteInviteInput::from($request['input'])
        );

        return $action->execute();
    }

    public function updateUserEmail(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        //sent email notification
        $updateEmail = $user->updateEmail($request['email']);
        $updateEmailNotification = new ChangeEmailUserLogged($user);
        $updateEmailNotification->setFromUser($user);

        $user->notify($updateEmailNotification);

        return $updateEmail;
    }

    public function updateUserDisplayName(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();
        UsersRepository::belongsToThisApp($user, app(Apps::class));

        return $user->updateDisplayName($request['displayname'], app(Apps::class));
    }

    public function createUser(mixed $rootValue, array $request): Users
    {
        $user = auth()->user();

        UsersRepository::belongsToThisApp($user, app(Apps::class));

        $request['data']['password'] = Str::random(15);
        $data = RegisterInput::fromArray($request['data']);
        $user = new CreateUserAction($data);

        return $user->execute();
    }
}
