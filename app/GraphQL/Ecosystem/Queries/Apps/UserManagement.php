<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
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
    public function getAllAppUsers(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        /**
         * @var Builder
         */
        return  Users::select('users.*')
                ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
                ->where('users_associated_apps.apps_id', app(Apps::class)->getId())
                ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
                ->groupBy(
                    'users.id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                    'users.created_at',
                    'users.updated_at',
                    'users.is_deleted',
                    'users.uuid'
                );
    }
}
