<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Users;

use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Hash;
use Kanvas\Notifications\Templates\ChangePasswordUserLogged;
use Kanvas\Users\Models\UserLinkedSources;
use Kanvas\Users\Repositories\UsersRepository;

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
