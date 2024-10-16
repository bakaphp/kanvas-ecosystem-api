<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries\Follows;

use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class FollowQueries
{
    /**
     * isFollowing
     */
    public function isFollowing(mixed $root, array $request): bool
    {
        $whoIsFollowing = UsersRepository::getUserOfAppById($request['user_id']);
        $user = auth()->user();
        return $user->isFollowing($whoIsFollowing);
    }

    /**
     * getTotalFollowers
     */
    public function getTotalFollowers(mixed $root, array $request): int
    {
        $user = UsersRepository::getUserOfAppById($request['user_id']);

        return UsersFollowsRepository::getTotalFollowers($user);
    }
}
