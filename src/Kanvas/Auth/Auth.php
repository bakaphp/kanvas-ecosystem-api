<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Baka\Support\Password;
use Baka\Users\Contracts\UserInterface;
use Exception;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Hash;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Sessions\Models\Sessions;
use Kanvas\Users\Enums\StatusEnums;
use Kanvas\Users\Models\Users;
use Lcobucci\JWT\Token;
use stdClass;

class Auth
{
    /**
     * User login.
     *
     * @param string $email
     * @param string $password
     * @param string $userIp
     *
     * @return Users
     */
    public static function login(
        LoginInput $loginInput
    ) : UserInterface {
        $user = Users::getByEmail($loginInput->getEmail());

        //first we find the user
        if (!$user) {
            throw new AuthenticationException('Invalid email or password.');
        }

        self::loginAttemptsValidation($user);

        //password verification
        if (Hash::check($loginInput->getPassword(), $user->password) && $user->isActive()) {
            Password::rehash($loginInput->getPassword(), $user);
            self::resetLoginTries($user);

            return $user;
        } elseif (!$user->isActive()) {
            throw new AuthenticationException('Invalid email or password.');
        } elseif ($user->isBanned()) {
            throw new AuthenticationException('User has been banned, please contact support.');
        } else {
            throw new AuthenticationException('User is not active, please contact support.');
        }
    }

    /**
     * User login.
     *
     * @param string $email
     * @param string $password
     * @param string $userIp
     *
     * @return Users
     */
    public static function specificAppLogin(
        LoginInput $loginInput
    ) : UserInterface {
        $user = Users::getByEmail($loginInput->getEmail());

        //first we find the user
        if (!$user) {
            throw new AuthenticationException('Invalid email or password.');
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
            throw new AuthenticationException('Invalid email or password.');
        } elseif ($user->isBanned()) {
            throw new AuthenticationException('User has been banned, please contact support.');
        } else {
            throw new AuthenticationException('User is not active, please contact support.');
        }
    }

    /**
     * Check the user login attempt to the app.
     *
     * @param Users $user
     *
     * @throws Exception
     *
     * @return bool
     */
    protected static function loginAttemptsValidation(UserInterface $user) : bool
    {
        //load config
        $config = new stdClass();
        $config->login_reset_time = getenv('AUTH_MAX_AUTOLOGIN_TIME');
        $config->max_login_attempts = getenv('AUTH_MAX_AUTOLOGIN_ATTEMPS');
        //$config->max_login_attempts = getenv('AUTH_MAX_AUTOLOGIN_ATTEMPTS');

        // If the last login is more than x minutes ago, then reset the login tries/time
        if ($user->user_last_login_try && $config->login_reset_time && $user->user_last_login_try < (time() - ($config->login_reset_time * 60))) {
            $user->user_login_tries = 0; //turn back to 0 attempt, success
            $user->user_last_login_try = 0;
            $user->updateOrFail();
        }

        // Check to see if user is allowed to login again... if his tries are exceeded
        if ($user->user_last_login_try
            && $config->login_reset_time
            && $config->max_login_attempts
            && $user->user_last_login_try >= (time() - ($config->login_reset_time * 60))
            && $user->user_login_tries >= $config->max_login_attempts) {
            throw new AuthenticationException(sprintf(_('You have exhausted all login attempts.'), $config->max_login_attempts));
        }

        return true;
    }

    /**
     * Reset login tries.
     *
     * @param Users $user
     *
     * @return bool
     */
    protected static function resetLoginTries(UserInterface $user) : bool
    {
        $user->lastvisit = date('Y-m-d H:i:s');
        $user->user_login_tries = 0;
        $user->user_last_login_try = 0;
        return $user->updateOrFail();
    }

    /**
     * Update login tries for the given user.
     *
     * @return bool
     */
    protected static function updateLoginTries(UserInterface $user) : bool
    {
        if ($user->getId() != StatusEnums::ANONYMOUS->getValue()) {
            $user->user_login_tries += 1;
            $user->user_last_login_try = time();
            return $user->updateOrFail();
        }

        return false;
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
