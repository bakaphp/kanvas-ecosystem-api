<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Types;

use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;

class UserType
{
    /**
     * Return the user address formatted for the response type.
     */
    public function address(Users $user, array $request): Users
    {
        $user->address = [
            'address_1' => $user->address_1,
            'address_2' => $user->address_2,
            'zip_code' => $user->zip_code,
        ];

        return $user;
    }

    /**
     * Return the user contact info formatted for the response type.
     */
    public function contact(Users $user, array $request): Users
    {
        $app = app(Apps::class);
        $user->contact = [
            'phone_number' => $user->phone_number,
            'cell_phone_number' => $user->cell_phone_number,
            'two_step_phone_number' => $user->getAppProfile($app)->two_step_phone_number,
        ];

        return $user;
    }
}
