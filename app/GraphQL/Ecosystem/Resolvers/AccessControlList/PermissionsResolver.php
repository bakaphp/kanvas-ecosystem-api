<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Resolvers\AccessControlList;

use Kanvas\Users\Repositories\UsersRepository;

class PermissionsResolver
{
    /**
     * can.
     */
    public function can(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getById($request['userId'], auth()->user()->getCurrentCompany()->getId());

        return $user->can($request['permission']);
    }
}
