<?php

declare(strict_types=1);

namespace Baka\Support;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Hash;
use Kanvas\Auth\Contracts\Authenticatable;

class Password extends Hash
{

    /**
     * Given any entity with password , verify if the password need rehash and update it.
     *
     * @param string $password
     * @param Authenticatable $entity
     *
     * @return bool
     */
    public static function rehash(string $password, Authenticatable $entity) : bool
    {
        if (self::needsRehash($entity->password)) {
            $entity->password = self::make($password);
            $entity->updateOrFail();

            return true;
        }

        return false;
    }
}
