<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\Auth\Services\UserManagement as UserManagementService;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class UserManagement
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
}
