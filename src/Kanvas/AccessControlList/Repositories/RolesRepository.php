<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

class RolesRepository
{
    public static function getByMixedParamFromCompany(int|string $param, ?Companies $company = null): Role
    {
        return is_numeric($param) ? RolesRepository::getByIdFromCompany((int) $param) : RolesRepository::getByNameFromCompany($param);
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByNameFromCompany(string $name, ?Companies $company = null, ?AppInterface $app = null): Role
    {
        $app = $app ?? app(Apps::class);
        return Role::where('name', $name)
            ->where('scope', RolesEnums::getScope($app, null))
            ->firstOrFail();
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByIdFromCompany(int $id, ?Companies $company = null, ?AppInterface $app = null): Role
    {
        $app = $app ?? app(Apps::class);
        return Role::where('id', $id)
                ->where('scope', RolesEnums::getScope($app, null))
                ->firstOrFail();
    }

    /**
     * getAllRoles.
     * @psalm-suppress MixedReturnStatement
     */
    public static function getAllRoles(?AppInterface $app = null): Collection
    {
        $app = $app ?? app(Apps::class);
        return Role::where('scope', RolesEnums::getScope($app, null))
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get app list of default roles.
     */
    public static function getAppRoles(AppInterface $app): Collection
    {
        return Role::where('scope', RolesEnums::getScope($app))->get();
    }
}
