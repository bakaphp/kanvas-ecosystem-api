<?php

declare(strict_types=1);

namespace Kanvas\Auth\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\DataTransferObject\LoginInput;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Users\Models\Users;

trait AuthTrait
{
    /**
     * Login user.
     *
     * @param string
     */
    protected function login(LoginInput $loginInput): Users
    {
        $remember = 1;
        $admin = 0;
        $app = app(Apps::class);

        $auth = new AuthenticationService($app);
        $userData = $auth->login($loginInput);

        return $userData;
    }
}
