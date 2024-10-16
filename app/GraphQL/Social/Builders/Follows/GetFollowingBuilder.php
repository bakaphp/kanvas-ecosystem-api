<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Follows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class GetFollowingBuilder
{
    /**
     * __invoke
     */
    public function __invoke(mixed $root, array $request): mixed
    {
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById($request['user_id'], $app);

        return UsersFollowsRepository::getFollowingBuilder($user, $app);
    }
}
