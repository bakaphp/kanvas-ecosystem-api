<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Follows;

use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
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
        $action = new FollowAction(auth()->user(), $user);
        $action->execute();

        return true;
    }

    /**
     * userUnfollow
     */
    public function userUnFollow(mixed $root, array $request): bool
    {
        //   $user = UsersRepository::getById($request['user_id']);
        $user = UsersRepository::getUserOfAppById($request['user_id']);
        $action = new UnFollowAction(auth()->user(), $user);

        return $action->execute();
    }
}
