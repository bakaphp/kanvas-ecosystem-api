<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

class UserDeviceMutation
{
    /**
     * changePassword.
     */
    public function register(mixed $root, array $req): bool
    {
        return true;
    }

    public function remove(mixed $root, array $req): bool
    {
        return true;
    }
}
