<?php

declare(strict_types=1);

namespace Kanvas\Roles\Repositories;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Apps\Enums\Defaults as AppsDefaults;
use Kanvas\Apps\Apps\Models\Apps;
use Kanvas\Companies\Companies\Models\Companies;
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
    public static function getByName(string $name, ?Companies $company = null) : Roles
    {
        $app = app(Apps::class);
        $userData = Auth::user();
        if ($company === null) {
            $company = Di::getDefault()->get('acl')->getCompany();
        }

        $role = Roles::where('name', $name)
                ->where('apps_id', $app->getKey())
                ->orWhere('apps_id', AppsDefaults::CANVAS_DEFAULT_APP_ID->getValue())
                ->where('companies_id', $company->getKey())
                ->orderBy('apps_id', 'desc')
                ->first();

        if (!is_object($role)) {
            throw new UnprocessableEntityException(
                _('Roles ' . $name . ' not found on this app ' . $app->getKey() . ' AND Company ' . $userData->currentCompanyId())
            );
        }

        return $role;
    }
}
