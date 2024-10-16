<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Follows;

use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Repositories\UsersRepository;

class FollowManagementMutation
{
    /**
     * userFollow
     */
    public function userFollow(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $userToFollow = UsersRepository::getUserOfAppById($request['user_id']);
        //$action = new FollowAction(auth()->user(), $user);
        //$action->execute();

        return $user->follow($userToFollow) instanceof UsersFollows;
    }

    /**
     * userUnfollow
     */
    public function userUnFollow(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $userToUnFollow = UsersRepository::getUserOfAppById($request['user_id']);
        //$action = new UnFollowAction(auth()->user(), $user);

        return $user->unFollow($userToUnFollow);
    }
}
