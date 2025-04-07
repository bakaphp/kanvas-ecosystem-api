<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class UserManagementQuery
{
    /**
     * all.
     */
    public function getAllCompanyUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $companiesId = auth()->user()->isAdmin() && ! empty($args['companies_id']) ? $args['companies_id'] : auth()->user()->currentCompanyId();
        $app = app(Apps::class);

        return Users::select(
            'users_associated_apps.users_id as id', // User ID
            'users.uuid',
            'users.dob',
            'users.sex',
            'users_associated_apps.firstname',
            'users_associated_apps.lastname',
            'users_associated_apps.email',
            'users.phone_number',
            'users.cell_phone_number',
            'users_associated_apps.apps_id',
            'users_associated_apps.companies_id',
            'users_associated_apps.identify_id',
            'users_associated_apps.two_step_phone_number',
            'users_associated_apps.is_active',
            'users_associated_apps.user_active',
            'users_associated_apps.user_role',
            'users_associated_apps.displayname',
            'users_associated_apps.phone_verified_at',
            'users_associated_apps.email_verified_at',
            'users_associated_apps.created_at',
            'users_associated_apps.updated_at'
        )
        ->join(
            'users_associated_company',
            'users_associated_company.users_id',
            'users.id'
        )
        ->join(
            'users_associated_apps',
            'users_associated_apps.users_id',
            'users.id'
        )
        ->where('users_associated_company.companies_id', $companiesId)
        ->where('users_associated_apps.apps_id', $app->getId())
        ->where('users_associated_company.is_deleted', StateEnums::NO->getValue())
        ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
        ->groupBy('users_associated_apps.users_id'); // Group by the correct user ID
    }

    /**
     * Get the current users from this branch.
     */
    public function getAllCompanyBranchUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $branchId = auth()->user()->isAdmin() && ! empty($args['getCurrentBranch']) ? $args['getCurrentBranch'] : auth()->user()->currentBranchId();
        $app = app(Apps::class);

        return Users::select(
            'users_associated_apps.users_id as id', // User ID
            'users.uuid',
            'users.dob',
            'users.sex',
            'users_associated_apps.firstname',
            'users_associated_apps.lastname',
            'users_associated_apps.email',
            'users.phone_number',
            'users.cell_phone_number',
            'users_associated_apps.apps_id',
            'users_associated_apps.companies_id',
            'users_associated_apps.identify_id',
            'users_associated_apps.two_step_phone_number',
            'users_associated_apps.is_active',
            'users_associated_apps.user_active',
            'users_associated_apps.user_role',
            'users_associated_apps.displayname',
            'users_associated_apps.phone_verified_at',
            'users_associated_apps.email_verified_at',
            'users_associated_apps.created_at',
            'users_associated_apps.updated_at'
        )
        ->join(
            'users_associated_company',
            'users_associated_company.users_id',
            'users.id'
        )
        ->join(
            'users_associated_apps',
            'users_associated_apps.users_id',
            'users.id'
        )
        ->where(
            'users_associated_company.is_deleted',
            StateEnums::NO->getValue()
        )
        ->where(
            'users_associated_company.companies_branches_id',
            $branchId
        )
        ->where(
            'users_associated_apps.is_deleted',
            StateEnums::NO->getValue()
        )
        ->where(
            'users_associated_apps.apps_id',
            $app->getId()
        )
        ->groupBy('users_associated_apps.users_id'); // Group by the correct user ID
    }
}
