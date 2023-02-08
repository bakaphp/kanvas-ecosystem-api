<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Mutations\Auth;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Exception;
use  Kanvas\Users\Repositories\UsersRepository;

class Auth
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
        if ($req['new_password'] == $req['new_password_confirmation']) {
            $user = UsersRepository::getByEmail(AuthFacade::user()->email);
            $user->password = bcrypt($req['new_password']);
            $user->save();
            return true;
        } else {
            throw new Exception('New password and confirmation password do not match');
            return false;
        }
    }
}
