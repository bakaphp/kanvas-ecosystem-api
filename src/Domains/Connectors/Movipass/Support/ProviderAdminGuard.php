<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\AccessControlList\Support\RoleChangeGuard;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

final class ProviderAdminGuard
{
    public static function assertMayHandOut(Users $actor, MovipassRolesEnum $role, Apps $app): void
    {
        if (! $actor->isAdmin()) {
            throw new AuthorizationException('Only an app administrator can manage provider users.');
        }

        RoleChangeGuard::assertCanGrant($actor, $role->value, $app);
    }

    public static function assertMayManageTarget(
        Users $actor,
        Users $target,
        MovipassRolesEnum $role,
        Apps $app
    ): void {
        self::assertMayHandOut($actor, $role, $app);

        if ($target->isAdmin()) {
            throw new AuthorizationException('Administrators and owners are managed from the admin panel, not by the agent.');
        }

        RoleChangeGuard::assertCanChange($actor, $target, $role->value, $app);
        UsersRepository::belongsToThisApp($target, $app);
    }

    public static function assertCompanyInApp(Companies $company, Apps $app): void
    {
        CompaniesRepository::hasAccessToThisApp($company, $app);
    }

    public static function inAppScope(Apps $app, callable $callback): mixed
    {
        return Bouncer::scope()->onceTo(RolesEnums::getScope($app), $callback);
    }

    public static function roleFor(MovipassRolesEnum $role, Apps $app): Role
    {
        return Role::query()
            ->where('name', $role->value)
            ->where('scope', RolesEnums::getScope($app))
            ->firstOrFail();
    }
}
