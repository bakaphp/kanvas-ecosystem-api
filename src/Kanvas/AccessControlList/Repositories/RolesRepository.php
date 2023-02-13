<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class RolesRepository
{
    /**
     * getAllRoles.
     *
     * @return ?Collection
     */
    public static function getAllRoles(): ?Collection
    {
        return Role::whereNull('scope')
            ->orWhere('scope', self::getScope())
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * getScope.
     *
     * @return string
     */
    public static function getScope(?Model $user = null, ?Companies $company = null): string
    {
        $app = app(Apps::class);
        $user = $user ?? auth()->user();
        $company = $company ?? $user->getCurrentCompany();

        return RolesEnums::getKey($app, $company);
    }

    /**
     * Get app list of default roles.
     *
     * @param Apps $app
     *
     * @return Collection
     */
    public static function getAppRoles(Apps $app): Collection
    {
        return Role::where('scope', RolesEnums::getKey($app))->get();
    }
}
