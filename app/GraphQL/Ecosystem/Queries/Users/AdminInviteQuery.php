<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Queries\Users;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\AdminInvite;
use Kanvas\Users\Repositories\AdminInviteRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AdminInviteQuery
{
    public function getInvite(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): AdminInvite
    {
        $app = app(Apps::class);

        return AdminInviteRepository::getByHash($args['hash'], $app);
    }
}
