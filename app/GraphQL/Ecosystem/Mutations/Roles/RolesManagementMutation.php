<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Roles;

use Baka\Support\Str;
use Bouncer;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Silber\Bouncer\Database\Role as SilberRole;

class RolesManagementMutation
{
    /**
     * assignRoleToUser.
     */
    public function assignRoleToUser(mixed $rootValue, array $request): bool
    {
        $auth = auth()->user();
        $company = $auth->getCurrentCompany();
        $userId = (int) $request['userId'];
        $app = app(Apps::class);

        $role = RolesRepository::getByMixedParamFromCompany($request['role']);

        if ($auth->isAppOwner()) {
            $user = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            $user = UsersRepository::getUserOfCompanyById($company, $userId);
        }

        $assign = new AssignRoleAction(
            $user,
            $role
        );
        $assign->execute();

        return true;
    }

    /**
     * removeRoleFromUser.
     */
    public function removeRoleFromUser(mixed $rootValue, array $request): bool
    {
        $auth = auth()->user();
        $company = $auth->getCurrentCompany();
        $userId = (int) $request['userId'];
        $app = app(Apps::class);

        $role = RolesRepository::getByMixedParamFromCompany($request['role']);

        if ($auth->isAppOwner()) {
            $user = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            $user = UsersRepository::getUserOfCompanyById($company, $userId);
        }

        $user->retract($role->name);

        return true;
    }

    /**
     * givePermissionToUser.
     */
    public function givePermissionToUser(mixed $rootValue, array $request): bool
    {
        $auth = auth()->user();
        $company = $auth->getCurrentCompany();
        $userId = (int) $request['userId'];
        $app = app(Apps::class);

        if ($auth->isAppOwner()) {
            $user = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            $user = UsersRepository::getUserOfCompanyById($company, $userId);
        }

        Bouncer::allow($user)->to(Str::slug($request['permission']));

        return true;
    }

    /**
     * removePermissionToUser.
     */
    public function removePermissionToUser(mixed $rootValue, array $request): bool
    {
        $auth = auth()->user();
        $company = $auth->getCurrentCompany();
        $userId = (int) $request['userId'];
        $app = app(Apps::class);

        if ($auth->isAppOwner()) {
            $user = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            $user = UsersRepository::getUserOfCompanyById($company, $userId);
        }

        Bouncer::disallow($user)->to($request['permission']);

        return true;
    }

    /**
     * createRole.
     */
    public function createRole(mixed $rootValue, array $request): SilberRole
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }

        $role = new CreateRoleAction(
            $request['name'],
            $request['title']
        );

        return $role->execute(auth()->user()->getCurrentCompany());
    }

    /**
     * updateRole.
     */
    public function updateRole(mixed $rootValue, array $request): SilberRole
    {
        $user = auth()->user();

        if (! $user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }

        $role = new UpdateRoleAction(
            (int) $request['id'],
            $request['name'],
            $request['title'] ?? null
        );

        return $role->execute(auth()->user()->getCurrentCompany());
    }
}
