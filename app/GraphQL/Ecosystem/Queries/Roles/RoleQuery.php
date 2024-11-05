<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Roles;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class RoleQuery
{
    /**
     * getAllRoles.
     * @psalm-suppress MixedReturnStatement
     */
    public function getAllRoles(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        return Role::where(
            'scope',
            RolesEnums::getScope(app(Apps::class), null)
        );
    }

    /**
     * hasRole.
     */
    public function hasRole(mixed $_, array $request): bool
    {
        $app = app(Apps::class);
        $role = RolesRepository::getByMixedParamFromCompany(
            param: $request['role'],
            app: $app
        );

        $user = UsersRepository::getUserOfCompanyById(
            auth()->user()->getCurrentCompany(),
            (int) $request['userId']
        );

        return $user->isAn($role->name);
    }
}
