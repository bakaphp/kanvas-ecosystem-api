<?php

declare(strict_type=1);

namespace App\GraphQL\Social\Builders\Follows;

use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Repositories\UsersRepository;

class GetFollowingBuilder
{
    /**
     * __invoke
     */
    public function __invoke(mixed $root, array $request): mixed
    {
        $user = UsersRepository::getUserOfAppById($request['users_id']);

        return UsersFollowsRepository::getFollowingBuilder($user);
    }
}
