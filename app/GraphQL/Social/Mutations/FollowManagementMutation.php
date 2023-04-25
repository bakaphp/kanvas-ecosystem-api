<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations;

use Kanvas\Social\Follow;
use Kanvas\Users\Repositories\UsersRepository;

class FollowManagementMutation
{
    /**
     * userFollow
     *
     * @return void
     */
    public function userFollow(mixed $root, array $request): bool
    {
        //   $user = UsersRepository::getById($request['user_id']);
        $user = UsersRepository::getUserOfAppById($request['user_id']);
        Follow::follow(auth()->user(), $user);

        return Follow::isFollowing(auth()->user(), $user);
    }

    /**
     * userUnfollow
     */
    public function userUnFollow(mixed $root, array $request): bool
    {
        //   $user = UsersRepository::getById($request['user_id']);
        $user = UsersRepository::getUserOfAppById($request['user_id']);
        Follow::unFollow(auth()->user(), $user);

        return Follow::isFollowing(auth()->user(), $user);
    }
}
