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
            $userCompanyIds = DB::table('users_associated_company')
                ->where('users_id', $user->getId())
                ->where('companies_id', '>', 0)
                ->where('is_deleted', StateEnums::NO->getValue())
                ->pluck('companies_id');

            return Users::select('users.*')
                ->join('users_associated_apps', 'users_associated_apps.users_id', '=', 'users.id')
                ->where('users_associated_apps.apps_id', $app->getId())
                ->where('users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
                ->whereExists(function ($query) use ($userCompanyIds) {
                    $query->select(DB::raw(1))
                        ->from('users_associated_company')
                        ->whereRaw('users_associated_company.users_id = users.id')
                        ->whereIn('users_associated_company.companies_id', $userCompanyIds)
                        ->where('users_associated_company.is_deleted', StateEnums::NO->getValue());
                })
                ->with([
                    'companies', // Eager load companies relationship
                    'roles',      // Eager load roles relationship
                ]);
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
