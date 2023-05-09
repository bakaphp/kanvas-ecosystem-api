<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Repositories;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;

class UsersFollowsRepository
{
    /**
     * getByUserAndEntity
     */
    public static function getByUserAndEntity(Users $user, EloquentModel $entity): ?UsersFollows
    {
        return UsersFollows::where('users_id', $user->id)
            ->where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->first();
    }

    /**
     * isFollowing
     */
    public static function isFollowing(Users $user, EloquentModel $entity): bool
    {
        return (bool) self::getByUserAndEntity($user, $entity);
    }

    /**
     * getFollowers
     */
    public static function getFollowers(EloquentModel $entity): array
    {
        $followers = UsersFollows::where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->get();

        $users = [];
        foreach ($followers as $follower) {
            $users[] = $follower->user;
        }

        return $users;
    }

    /**
     * getFollowing
     */
    public static function getFollowing(Users $user): array
    {
        $followings = UsersFollows::where('users_id', $user->id)
            ->get();
        $following = [];
        foreach ($followings as $follow) {
            $following[] = $follow->entity;
        }

        return $following;
    }

    /**
     * getTotalFollowers
     */
    public static function getTotalFollowers(EloquentModel $entity): int
    {
        return UsersFollows::where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->count();
    }
}
