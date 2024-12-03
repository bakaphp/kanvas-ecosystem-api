<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;

class UserRoleRepository
{
    public static function getAllUsersOfRole(
        Role $role,
        AppInterface $app,
        ?CompanyInterface $company = null
    ): Builder {
        $query = Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
            ->join('assigned_roles', 'assigned_roles.entity_id', '=', 'users.id')
            ->where('assigned_roles.role_id', '=', $role->id)
            ->where('users_associated_apps.apps_id', '=', $app->id)
            ->where('assigned_roles.scope', '=', $role->scope)
            ->where('assigned_roles.entity_type', '=', Users::class)
            ->groupBy('users.id');

        if ($company) {
            $query->where('users_associated_apps.companies_id', '=', $company->id);
        } else {
            $query->where('users_associated_apps.companies_id', '=', AppEnums::GLOBAL_COMPANY_ID->getValue());
        }

        return $query;
    }
}
