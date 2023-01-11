<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use Kanvas\Auth\Auth;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Users\Models\Users;

trait AuthTrait
{
    /**
     * Login user.
     *
     * @param string
     *
     * @return Users
     */
    protected function login(LoginInput $loginInput) : Users
    {
        $remember = 1;
        $admin = 0;

        $userData = Auth::login(
            $loginInput
        );

        return $userData;
    }
}
