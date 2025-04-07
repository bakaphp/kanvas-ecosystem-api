<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Users;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\UsersInvite;
use Kanvas\Users\Repositories\UsersInviteRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class UserInviteQuery
{
    public function getInvite(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): UsersInvite
    {
        $app = app(Apps::class);

        return UsersInviteRepository::getByHash($args['hash'], $app);
    }
}
