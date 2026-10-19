<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Support;

use Baka\Contracts\AppInterface;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;

/**
 * Roles are app-wide, so an admin of one company would otherwise change roles for every company in
 * the app: only an owner of the app may grant or remove the Owner role, touch the roles of a user who
 * is an owner, or change what a system role (Owner, Admin, Users...) is allowed to do.
 */
final class RoleChangeGuard
{
    public static function assertCanChange(
        Users $actor,
        Users $target,
        string $roleName,
        AppInterface $app
    ): void {
        if ($actor->isOwnerOf($app)) {
            return;
        }

        if ($roleName === RolesEnums::OWNER->value || $target->isOwnerOf($app)) {
            throw new AuthorizationException('Only an owner of this app can change owner roles.');
        }
    }

    public static function assertCanEditRole(Users $actor, string $roleName, AppInterface $app): void
    {
        if (RolesEnums::isEnumValue($roleName) && ! $actor->isOwnerOf($app)) {
            throw new AuthorizationException('Only an owner of this app can change a system role.');
        }
    }
}
