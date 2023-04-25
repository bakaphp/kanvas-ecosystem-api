<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Queries;

use Kanvas\Social\Follow;
use Kanvas\Users\Repositories\UsersRepository;

class FollowQueries
{
    /**
     * isFollowing
     */
    public function isFollowing(mixed $root, array $request): bool
    {
        //   $user = UsersRepository::getById($request['user_id']);
        $user = UsersRepository::getUserOfAppById($request['user_id']);

        return Follow::isFollowing(auth()->user(), $user);
    }
}
