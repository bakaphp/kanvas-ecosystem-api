<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\Users;

class UserAppRepository
{
    public static function getAllAppUsers(AppInterface $app): Builder
    {
        return Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
            ->where('users_associated_apps.apps_id', $app->getId())
            ->where('users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users_associated_company')
                    ->whereRaw('users_associated_company.users_id = users.id')
                    ->where('users_associated_company.companies_id', '>', 0);
            });
    }
}
