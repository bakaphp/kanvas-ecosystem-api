<?php

declare(strict_type=1);

namespace App\GraphQL\Social\Builders\Follows;

use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;
use Illuminate\Database\Eloquent\Builder;

class FollowBuilder
{
    /**
     * getFollowers
     *
     * @param  mixed $request
     */
    public function getFollowers(mixed $root, array $request): Builder
    {
        $user = UsersRepository::getUserOfAppById($request['users_id']);

        return UsersFollowsRepository::getFollowersBuilder($user);
    }

    public function getFollowing(mixed $root, array $request): Builder
    {
        $user = UsersRepository::getUserOfAppById($request['users_id']);

        return UsersFollowsRepository::getFollowingBuilder($user);
    }

    public function getEntityFollowers(mixed $root, array $request): Builder
    {
        //return users following the entity
        return UsersFollowsRepository::getFollowersBuilder($root);
    }
}
