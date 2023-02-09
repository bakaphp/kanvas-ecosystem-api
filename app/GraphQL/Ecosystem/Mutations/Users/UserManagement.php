<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Users;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Exception;
use Kanvas\Users\Repositories\UsersRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;

class UserManagement
{
    /**
     * changePassword
     *
     * @param  mixed $root
     * @param  array $req
     * @return void
     */
    public function changePassword(mixed $root, array $req)
    {
        $validator = Validator::make($req, [
            'new_password' => ['required', 'confirmed', Password::min(8)],
        ]);
        if ($validator->validate()) {
            $user = UsersRepository::getByEmail(AuthFacade::user()->email);
            $user->password = Hash::make($req['new_password']);
            $user->save();
            $user->notify(new ChangePasswordUserLogged($user));
            return true;
        } else {
            throw new Exception('New password and confirmation password do not match');
            return false;
        }
    }
}
