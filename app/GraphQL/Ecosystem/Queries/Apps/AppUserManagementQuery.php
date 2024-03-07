<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AppUserManagementQuery
{
    /**
     * all.
     */
    public function getAllAppUsers(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        /**
         * @var Builder
         */

        //  return UsersAssociatedApps::select('users.*', 'users_associated_apps.is_active')
        //  ->join('users', 'users.id', 'users_associated_apps.users_id')
        //  ->where('users_associated_apps.apps_id', app(Apps::class)->getId())
        //  ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
        //  ;
        return Users::select('users.*')
            ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
            ->where('users_associated_apps.apps_id', app(Apps::class)->getId())
            ->where('users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('users_associated_company')
                    ->whereRaw('users_associated_company.users_id = users.id')
                    ->where('users_associated_company.companies_id', '>', 0);
            });
    }

    public function getCompaniesByUser(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        Users::select('companies.*')
        ->join('user_associated_company', 'user_associated_company.user_id', '=', 'users.id')
        ->join('companies', 'companies.id', '=', 'user_associated_company.company_id')
        ->join('user_associated_apps', function ($join) {
            $join->on('user_associated_apps.company_id', '=', 'user_associated_company.company_id')
                 ->where('user_associated_apps.app_id', '=', app(Apps::class)->getId())
                 ->where('user_associated_apps.is_deleted', '=', 0);
        })
        ->where('users.id', $args['users_id'])
        ->groupBy('companies.id');
    }
}
