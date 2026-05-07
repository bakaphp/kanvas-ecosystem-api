<?php

declare(strict_types=1);

namespace Baka\Support;

use Illuminate\Support\Facades\Hash;
use Kanvas\Auth\Contracts\Authenticatable;
use Kanvas\Users\Models\UsersAssociatedApps;

class Password extends Hash
{
    /**
     * Given any entity with password , verify if the password need rehash and update it.
     */
    public static function rehash(string $password, Authenticatable $entity): bool
    {
        if (self::needsRehash($entity->password)) {
            $entity->password = self::make($password);

            //legacy update user
            if ($entity instanceof UsersAssociatedApps) {
                $entity->user->password = $entity->password;
                $entity->user->updateOrFail();
            }

            $entity->updateOrFail();

            return true;
        }

        return false;
    }
}
