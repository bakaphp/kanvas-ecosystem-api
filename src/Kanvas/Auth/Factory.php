<?php

declare(strict_types=1);

namespace Kanvas\Auth;

use Kanvas\Apps\Models\Apps;

class Factory
{
    /**
     * Create the Auth factory.
     *
     * @param bool $ecosystemAuth
     *
     * @return mixed
     */
    public static function create(bool $ecosystemAuth) : mixed
    {
        $user = null;
        switch ($ecosystemAuth) {
            case false:
                $user = new Apps();
                break;

            default:
                $user = new Auth();
                break;
        }

        return $user;
    }
}
