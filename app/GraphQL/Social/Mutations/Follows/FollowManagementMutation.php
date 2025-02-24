<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Mutations\Follows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Actions\FollowAction;
use Kanvas\Social\Follows\Actions\UnFollowAction;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Repositories\UsersRepository;
use Kanvas\Connectors\Recombee\Actions\AddRatingUserItemAction;

class FollowManagementMutation
{
    /**
     * userFollow
     */
    public function userFollow(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $userToFollow = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);

        if ($user->getId() === $userToFollow->getId()) {
            return false;
        }

        //$action = new FollowAction(auth()->user(), $user);
        //$action->execute();

        $follow = $user->follow($userToFollow) instanceof UsersFollows;

        (new AddRatingUserItemAction($app, $user, $userToFollow->getId(), 1.0))->execute();

        return $follow;
    }

    /**
     * userUnfollow
     */
    public function userUnFollow(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $userToUnFollow = UsersRepository::getUserOfAppById((int) $request['user_id'], $app);
        //$action = new UnFollowAction(auth()->user(), $user);
        if ($user->getId() === $userToUnFollow->getId()) {
            return false;
        }

        $unFollow = $user->unFollow($userToUnFollow);

        (new AddRatingUserItemAction($app, $user, $userToUnFollow->getId(), -1.0))->execute();

        return $unFollow;
    }
}
