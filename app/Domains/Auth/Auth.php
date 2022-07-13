<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Canvas\Models\Sessions;
use Illuminate\Support\Facades\Hash;
use Kanvas\Users\Users\Models\Users;
use Exception;
use Lcobucci\JWT\Token;

class Auth
{
    /**
     * User login.
     *
     * @param string $email
     * @param string $password
     * @param int $autologin
     * @param int $admin
     * @param string $userIp
     *
     * @return Users
     */
    public static function login(string $email, string $password, int $autologin = 1, int $admin = 0, ?string $userIp = null) : Users
    {
        $email = ltrim(trim($email));
        $password = ltrim(trim($password));

        $user = Users::getByEmail($email);

        //first we find the user
        if (!$user) {
            throw new Exception('Invalid email or password.');
        }

        // /**
        //  * @todo Remove this in future versions
        //  */
        // if (!$user->get($user->getDefaultCompany()->branchCacheKey())) {
        //     $user->set($user->getDefaultCompany()->branchCacheKey(), $user->getDefaultCompany()->branch->getId());
        // }

        //password verification
        if (Hash::check($password, $user->password) && $user->isActive()) {
            //rehash password
            $rehashedPass = Hash::make($password);

            $user->password = $rehashedPass;
            $user->save();

            return $user;
        } elseif ($user->isActive()) {
            throw new Exception('Invalid email or password.');
        } elseif ($user->isBanned()) {
            throw new Exception('User has been banned, please contact support.');
        } else {
            throw new Exception('User is not active, please contact support.');
        }
    }

    /**
     * Undocumented function.
     *
     * @param UserInterface $user
     * @param Token $token
     *
     * @return bool
     */
    public static function logout(UserInterface $user, Token $token) : bool
    {
        $sessionId = $token->claims()->get('sessionId') ?? null;

        $session = new Sessions();
        $session->end($user, $sessionId);

        return true;
    }
}
