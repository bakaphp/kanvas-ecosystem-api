<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Users;

use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

final class UsersList
{
    /**
     * Get user from the current company.
     *
     * @param mixed $rootValue
     * @param array $request
     *
     * @return Users
     */
    public function getFromCurrentCompany($rootValue, array $request): Users
    {
        return UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['id']
        );
    }
}
