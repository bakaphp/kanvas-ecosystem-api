<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Companies;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class UserManagement
{
    /**
     * all.
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function getAllCompanyUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        return  Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', auth()->user()->currentCompanyId());
    }

    /**
     * Get the current users from this branch.
     *
     * @param mixed $root
     * @param array $args
     * @param GraphQLContext $context
     * @param ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function getAllCompanyBranchUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        return  Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_branches_id', auth()->user()->currentBranchId());
    }
}
