<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
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

        $builder = UserAppRepository::getAllAppUsers($app);

        $user = auth()->user();

        if ($user->can('limited-company-access')) {
            // Get company IDs that the current user belongs to
            $userCompanyIds = DB::table('users_associated_apps')
                ->where('users_id', $user->getId())
                ->where('apps_id', $app->getId())
                ->where('companies_id', '>', 0) // Only get actual company associations
                ->where('is_deleted', 0)
                ->pluck('companies_id');

            // Get user IDs from those companies (including users with companies_id > 0)
            $allowedUserIds = DB::table('users_associated_apps')
                ->whereIn('companies_id', $userCompanyIds)
                ->where('apps_id', $app->getId())
                ->where('is_deleted', 0)
                ->pluck('users_id');

            // Filter the main builder to only show users from those companies
            // The main query still gets users with companies_id = 0 (app-level data)
            // but we limit which users based on their company associations
            return $builder->whereIn('users.id', $allowedUserIds);
        }

        return $builder;
    }

    public function getAllAppUsersNoAdmin(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $app = app(Apps::class);
        $user = auth()->user();

        $query = UserAppRepository::getAllAppUsers($app);

        // Non-owners can only see themselves in the app
        if (! $user->isAppOwner()) {
            $query->where('users.id', $user->id);
        }

        return $query;
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

    public function getAppAdminUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
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
