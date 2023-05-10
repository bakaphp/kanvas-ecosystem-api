<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Repositories;

use Illuminate\Database\Eloquent\Builder;
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
        return self::getFollowersBuilder($entity)->get();
    }

    /**
     * getFollowersBuilder
     */
    public static function getFollowersBuilder(EloquentModel $entity): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');

        return UsersFollows::join($ecosystemConnection . '.users', 'users.id', '=', 'users_follows.users_id')
            ->where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->where('entity_namespace', get_class($entity))
            ->select('users.*');
    }

    /**
     * getFollowing
     */
    public static function getFollowing(Users $user): array
    {
        return self::getFollowingBuilder($user)->get();
    }

    /**
     * getFollowingBuilder
     */
    public static function getFollowingBuilder(Users $user): Builder
    {
        return UsersFollows::where('users_id', $user->id);
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
