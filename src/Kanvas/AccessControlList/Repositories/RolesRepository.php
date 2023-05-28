<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;

class RolesRepository
{
    /**
     * getAllRoles.
     *
     * @return ?Collection
     */
    public static function getAllRoles(): ?Collection
    {
        return Role::where('scope', RolesEnums::getScope(app(Apps::class), null))
            ->orWhere('scope', self::getScope())
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get app list of default roles.
     */
    public static function getAppRoles(Apps $app): Collection
    {
        return Role::where('scope', RolesEnums::getScope($app))->get();
    }
}
