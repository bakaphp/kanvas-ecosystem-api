<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Types;

use Kanvas\Users\Models\Users as ModelsUsers;

class Users
{
    /**
     * Return the user address formatted for the response type
     *
     * @param ModelsUsers $user
     * @param array $request
     * @return ModelsUsers
     */
    public function userAddress(ModelsUsers $user, array $request): ModelsUsers
    {
        $user->address = [
            "address_1" => $user->address_1,
            "address_2" => $user->address_2,
            "zip_code" => $user->zip_code
        ];

        return $user;
    }
}
