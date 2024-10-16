<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Follows;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class FollowBuilder
{
    /**
     * getFollowers
     *
     * @param  mixed $request
     */
    public function getFollowers(mixed $root, array $request): Builder
    {
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById($request['user_id'], $app);

        return UsersFollowsRepository::getFollowersBuilder($user, $app);
    }

    public function getFollowing(mixed $root, array $request): Builder
    {
        $app = app(Apps::class);
        $user = UsersRepository::getUserOfAppById($request['user_id'], $app);

        return UsersFollowsRepository::getFollowingBuilder($user, $app);
    }

    public function getEntityFollowers(mixed $root, array $request): Builder
    {
        //return users following the entity
        $app = app(Apps::class);
        return UsersFollowsRepository::getFollowersBuilder($root, $app);
    }
}
