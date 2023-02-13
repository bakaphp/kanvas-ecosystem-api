<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Repositories\UsersRepository;

class UserManagement
{
    /**
     * changePassword.
     *
     * @param  mixed $root
     * @param  array $req
     *
     * @return void
     */
    public function changePassword(mixed $root, array $req): bool
    {
        $user = UsersRepository::getByEmail(AuthFacade::user()->email);
        $user->password = Hash::make($req['new_password']);
        $user->saveOrFail();
        $user->notify(new ChangePasswordUserLogged($user));
        return true;
    }
}
