<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use Illuminate\Http\Request;
use Kanvas\Auth\Factory;
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

        $auth = Factory::create(true);

        $userData = $auth::login(
            $loginInput->email,
            $loginInput->password,
            $loginInput->ip
        );

        return $userData;
    }
}
