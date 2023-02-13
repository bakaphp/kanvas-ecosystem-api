<?php

declare(strict_types=1);

namespace Kanvas\Roles\Repositories;

use Kanvas\Apps\Enums\Defaults as AppsDefaults;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Roles\Models\Roles;

class RolesRepository
{
    /**
     * Get the entity by its name.
     *
     * @param string $name
     * @param Companies|null $company
     *
     * @return Roles
     *
     * @todo Need to fetch app and company id from ACL on container instead of apps and userdata from DI.
     */
    public static function getByName(string $name, Apps $app, Companies $company): Roles
    {
        $role = Roles::where('name', $name)
                ->where('apps_id', $app->id)
                ->whereIn('apps_id', [$app->id, AppsDefaults::ECOSYSTEM_APP_ID->getValue()])
                ->whereIn('companies_id', [$company->id, AppsDefaults::ECOSYSTEM_COMPANY_ID->getValue()])
                ->orderBy('apps_id', 'DESC')
                ->first();

        if (!is_object($role)) {
            throw new InternalServerErrorException(
                'Roles ' . $name . ' not found on this app ' . $app->getKey() . ' AND Company ' . $company->id
            );
        }

        return $role;
    }
}
