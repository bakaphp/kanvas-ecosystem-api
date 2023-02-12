<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Resolvers\AccessControlList;

use Kanvas\Users\Repositories\UsersRepository;

class PermissionsResolver
{
    /**
     * can.
     *
     * @param  mixed $rootValue
     * @param  array $request
     *
     * @return bool
     */
    public function can(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->defaultCompany->id);
        return $user->can($request['permission']);
    }
}
