<?php

declare(strict_types=1);

namespace Kanvas\Social;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Social\Actions\FollowAction;
use Kanvas\Social\Actions\UnFollowAction;
use Kanvas\Social\Models\UsersFollows;
use Kanvas\Social\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class Follow
{
    /**
     * follow
     *
     * @param  mixed $user
     * @param  mixed $entity
     */
    public static function follow(Users $user, EloquentModel $entity): UsersFollows
    {
        return (new FollowAction($user, $entity))->execute();
    }

    /**
     * unFollow
     */
    public static function unFollow(Users $user, EloquentModel $entity): bool
    {
        return (new UnFollowAction($user, $entity))->execute();
    }

    /**
     * isFollowing
     */
    public static function isFollowing(Users $user, EloquentModel $entity): bool
    {
        return UsersFollowsRepository::isFollowing($user, $entity);
    }
}
