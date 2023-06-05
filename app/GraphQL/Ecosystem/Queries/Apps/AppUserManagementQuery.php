<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Apps;

use Baka\Enums\StateEnums;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
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
        return  Users::select('users.*')
                ->join('users_associated_apps', 'users_associated_apps.users_id', 'users.id')
                ->where('users_associated_apps.apps_id', app(Apps::class)->getId())
                ->where('users_associated_apps.is_deleted', StateEnums::NO->getValue())
                ->groupBy(
                    'users.id',
                    'users.uuid',
                    'users.email',
                    'users.password',
                    'users.firstname',
                    'users.lastname',
                    'users.description',
                    'users.roles_id',
                    'users.displayname',
                    'users.default_company',
                    'users.default_company_branch',
                    'users.city_id',
                    'users.state_id',
                    'users.country_id',
                    'users.registered',
                    'users.lastvisit',
                    'users.sex',
                    'users.dob',
                    'users.timezone',
                    'users.phone_number',
                    'users.cell_phone_number',
                    'users.profile_privacy',
                    'users.profile_image',
                    'users.profile_header',
                    'users.profile_header_mobile',
                    'users.user_active',
                    'users.user_login_tries',
                    'users.user_last_login_try',
                    'users.session_time',
                    'users.session_page',
                    'users.welcome',
                    'users.user_activation_key',
                    'users.user_activation_email',
                    'users.user_activation_forgot',
                    'users.language',
                    'users.karma',
                    'users.votes',
                    'users.votes_points',
                    'users.banned',
                    'users.system_modules_id',
                    'users.status',
                    'users.address_1',
                    'users.address_2',
                    'users.zip_code',
                    'users.user_recover_code',
                    'users.created_at',
                    'users.updated_at',
                    'users.is_deleted',
                );
    }
}
