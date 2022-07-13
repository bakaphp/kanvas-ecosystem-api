<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use Kanvas\Auth\Factory;
use Kanvas\Users\Users\Models\Users;
use Illuminate\Http\Request;

trait AuthTrait
{
    /**
     * Login user.
     *
     * @param string
     *
     * @return Users
     */
    protected function loginUsers(Request $request, string $email, string $password) : Users
    {
        $userIp = $request->ip();
        $remember = 1;
        $admin = 0;

        $auth = Factory::create(true);

        $userData = $auth::login($email, $password, $remember, $admin, $userIp);

        return $userData;
    }
}
