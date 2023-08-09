<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Follows;

use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class GetFollowersBuilder
{
    /**
     * __invoke
     */
    public function __invoke(mixed $root, array $request): mixed
    {
        $user = UsersRepository::getUserOfAppById($request['user_id']);

        return UsersFollowsRepository::getFollowersBuilder($user);
    }
}
