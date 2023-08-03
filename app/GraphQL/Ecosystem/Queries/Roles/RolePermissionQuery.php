<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use Kanvas\Apps\Enums\DefaultRoles;
use Kanvas\Users\Repositories\UsersRepository;

class RolePermissionQuery
{
    /**
     * can.
     */
    public function can(mixed $rootValue, array $request): bool
    {
        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['userId']
        );

        if ($user->isAn((string) DefaultRoles::ADMIN->getValue())) {
            return true;
        }

        return $user->can($request['permission']);
    }
}
