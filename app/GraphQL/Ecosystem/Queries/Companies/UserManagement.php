<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class UserManagement
{
    /**
     * all.
     */
    public function getAllCompanyUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        /**
         * @var Builder
         */
        return  Users::select('users.*')
                ->join(
                    'users_associated_company',
                    'users_associated_company.users_id',
                    'users.id'
                )
                ->where(
                    'users_associated_company.companies_id',
                    auth()->user()->currentCompanyId()
                )
                ->where(
                    'users_associated_company.is_deleted',
                    StateEnums::NO->getValue()
                )
                ->groupBy('users.id');
    }

    /**
     * Get the current users from this branch.
     */
    public function getAllCompanyBranchUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        /**
         * @var Builder
         */
        return  Users::join(
            'users_associated_company', 
            'users_associated_company.users_id', 
            'users.id'
            )
            ->where(
                'users_associated_company.is_deleted', 
                StateEnums::NO->getValue()
            )
            ->where(
                'users_associated_company.companies_branches_id', 
                auth()->user()->currentBranchId()
            );
    }
}
