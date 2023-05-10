<?php

declare(strict_type=1);

namespace App\GraphQL\Social\Builders\Follows;

use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class FollowBuilder
{
    /**
     * getFollowers
     *
     * @param  mixed $request
     */
    public function getFollowers(mixed $root, array $request): mixed
    {
        $user = UsersRepository::getUserOfAppById($request['users_id']);

        return UsersFollowsRepository::getFollowersBuilder($user);
    }

    public function getFollowing(mixed $root, array $request): mixed
    {
        $user = UsersRepository::getUserOfAppById($request['users_id']);

        return UsersFollowsRepository::getFollowingBuilder($user);
    }
}
