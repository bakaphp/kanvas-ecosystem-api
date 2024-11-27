<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UserAppRepository;
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
        $app = app(Apps::class);

        return UserAppRepository::getAllAppUsers($app);
    }

    public function getAdminUserCompanies(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        return Users::select('companies.*')
                ->join('users_associated_company', 'users_associated_company.users_id', '=', 'users.id')
                ->join('companies', 'companies.id', '=', 'users_associated_company.companies_id')
                ->join('users_associated_apps', function ($join) {
                    $join->on('users_associated_apps.companies_id', '=', 'users_associated_company.companies_id')
                         ->where('users_associated_apps.apps_id', '=', app(Apps::class)->getId())
                         ->where('users_associated_apps.is_deleted', '=', 0);
                })
                ->where('users.id', $args['user_id'])
                ->groupBy('companies.id');
    }

    public function getAdminUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $appUuid = app(Apps::class)->getId();

        return Users::select('*')
        ->join('users_associated_apps', 'users.id', '=', 'users_associated_apps.users_id')
        ->join('apps_keys', function ($join) {
            $join->on('users_associated_apps.apps_id', '=', 'apps_keys.apps_id')
                 ->on('users.id', '=', 'apps_keys.users_id');
        })
        ->join('apps', 'users_associated_apps.apps_id', '=', 'apps.id')
        ->where('apps.id', $appUuid)
        ->select('users.*')
        ->distinct();
    }
}
