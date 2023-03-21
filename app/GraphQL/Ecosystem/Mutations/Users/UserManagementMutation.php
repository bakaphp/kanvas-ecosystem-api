<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\Auth\Services\UserManagement as UserManagementService;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Users\Actions\CreateInviteAction;
use Kanvas\Users\DataTransferObject\Invite as InviteDto;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Kanvas\Users\Actions\ProcessInviteAction;
use Kanvas\Users\DataTransferObject\CompleteInviteInput;

class UserManagementMutation
{
    /**
     * changePassword.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return bool
     */
    public function changePassword(mixed $root, array $req): bool
    {
        $user = UsersRepository::getByEmail(AuthFacade::user()->email);
        $user->password = Hash::make($req['new_password']);
        $user->saveOrFail();
        $user->notify(new ChangePasswordUserLogged($user));

        return true;
    }

    /**
     * Update user information.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return Users
     */
    public function updateUser(mixed $rootValue, array $request): Users
    {
        $userManagement = new UserManagementService(Users::getById(auth()->user()->id));
        $user = $userManagement->update($request['data']);

        return $user;
    }

    /**
     * insertInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return UsersInvite
     */
    public function insertInvite($rootValue, array $request): UsersInvite
    {
        $request = $request['input'];
        $invite = new CreateInviteAction(
            new InviteDto(
                $request['companies_branches_id'],
                $request['role_id'],
                $request['email'],
                $request['firstname'] ?? null,
                $request['lastname'] ?? null,
                $request['description'] ?? null,
            ),
            auth()->user()
        );
        return $invite->execute();
    }

    /**
     * deleteInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function deleteInvite($rootValue, array $request): bool
    {
        $invite = UsersInviteRepository::getById(
            $request['id'],
            auth()->user()->getCurrentCompany()
        );

        $invite->softDelete();
        return true;
    }

    /**
     * processInvite.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return UsersInvite
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
     * @param  array $request
     *
     * @return Users
     */
    public function process($rootValue, array $request): Users
    {
        $action = new ProcessInviteAction(
            CompleteInviteInput::from($request['input'])
        );
        return $action->execute();
    }
}
