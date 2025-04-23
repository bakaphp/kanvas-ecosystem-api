<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Mutations\Roles;

use Baka\Support\Str;
use Bouncer;
use Illuminate\Support\Facades\Redis;
use Kanvas\AccessControlList\Actions\AssignRoleAction;
use Kanvas\AccessControlList\Actions\BulkAllowRoleToPermissionAction;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\AccessControlList\Actions\UpdateRoleAction;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role as KanvasRole;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
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

        $role = RolesRepository::getByMixedParamFromCompany(
            param: $request['role'],
            app: $app
        );

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

        $role = RolesRepository::getByMixedParamFromCompany(
            param: $request['role'],
            app: $app
        );

        if ($auth->isAdmin()) {
            $user = UsersRepository::getUserOfAppById($userId, $app);
        } else {
            $user = UsersRepository::getUserOfCompanyById($company, $userId);
        }

        $user->retract($role->name);

        return true;
    }

    public function givePermissionToRole(mixed $rootValue, array $request): bool
    {
        $systemModule = SystemModulesRepository::getByModelName($request['systemModule'], app(Apps::class));
        Bouncer::allow($request['role'])->to($request['permission'], $systemModule->model_name);

        $roles = RolesRepository::getMapAbilityInModules($request['role']);
        Redis::set(RolesEnums::KEY_MAP->value, $roles);

        return true;
    }

    public function removePermissionToRole(mixed $rootValue, array $request): bool
    {
        $systemModule = SystemModulesRepository::getByModelName($request['systemModule'], app(Apps::class));
        Bouncer::disallow($request['role'])->to($request['permission'], $systemModule->model_name);

        $roles = RolesRepository::getMapAbilityInModules($request['role']);
        Redis::set(RolesEnums::KEY_MAP->value, $roles);

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
        Bouncer::allow($user)->to(Str::simpleSlug($request['permission']));

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
        $input = $request['input'];
        if (!$user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }

        if (RolesEnums::isEnumValue($input['name'])) {
            throw new ValidationException('You are not allowed to create system roles');
        }

        $role = new CreateRoleAction(
            $input['name'],
            $input['title'] ?? null
        );

        $role = $role->execute(auth()->user()->getCurrentCompany());
        $permissions = $input['permissions'];
        (new BulkAllowRoleToPermissionAction(
            app(Apps::class),
            $role,
            $permissions,
            key_exists('template_id', $input) ? SilberRole::find($input['template_id']) : null
        ))->execute();

        return KanvasRole::find($role->id);
    }

    /**
     * updateRole.
     */
    public function updateRole(mixed $rootValue, array $request): SilberRole
    {
        $user = auth()->user();

        if (!$user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }
        $input = $request['input'];

        $role = new UpdateRoleAction(
            (int) $request['id'],
            $input['name'] ?? null,
            $input['title'] ?? null
        );

        $role = $role->execute(auth()->user()->getCurrentCompany());
        Bouncer::disallow($role)->to($role->abilities->pluck('name')->toArray());
        $permissions = $input['permissions'];

        (new BulkAllowRoleToPermissionAction(
            app(Apps::class),
            $role,
            $permissions
        ))->execute();

        return KanvasRole::find($role->id);
    }

    public function deleteRole(mixed $rootValue, array $request): bool
    {
        $user = auth()->user();

        if (!$user->isAdmin()) {
            throw new AuthorizationException('You are not allowed to perform this action');
        }

        $role = KanvasRole::findOrFail($request['id']);

        if (RolesEnums::isEnumValue($role->name)) {
            throw new AuthorizationException('You are not allowed to delete system roles');
        }

        return $role->delete();
    }
}
