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
     * Get the role from the current app
     *
     * @param string $name
     * @param Apps $app
     * @param Companies $company
     * @return Roles
     */
    public static function getByName(string $name, Apps $app, Companies $company): Roles
    {
        $role = Roles::where('name', $name)
                ->where('apps_id', $app->id)
                ->whereIn('apps_id', [$app->id, AppsDefaults::ECOSYSTEM_APP_ID->getValue()])
                ->whereIn('companies_id', [$company->id, AppsDefaults::ECOSYSTEM_COMPANY_ID->getValue()])
                ->orderBy('apps_id', 'DESC')
                ->first();

        if (! $role instanceof Roles) {
            throw new InternalServerErrorException(
                'Roles ' . $name . ' not found on this app ' . $app->getKey() . ' AND Company ' . $company->id
            );
        }

        return $role;
    }
}
