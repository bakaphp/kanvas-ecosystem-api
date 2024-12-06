<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Users;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\Users;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class BlockUserBuilder
{
    public function getUsers(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $socialDb = config('database.connections.social.database');

        $app = app(Apps::class);

        return Users::query()
            ->join($socialDb . '.blocked_users', 'users.id', '=', 'blocked_users.blocked_users_id')
            ->where('blocked_users.is_deleted', 0)
            ->when($app, function (Builder $query) use ($app) {
                $query->where('apps_id', $app->id);
            })
            ->select('users.*');
    }
}
