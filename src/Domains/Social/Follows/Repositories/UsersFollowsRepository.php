<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;

class UsersFollowsRepository
{
    /**
     * getByUserAndEntity
     * @psalm-suppress MixedReturnStatement
     */
    public static function getByUserAndEntity(Users $user, EloquentModel $entity): ?UsersFollows
    {
        return UsersFollows::where('users_id', $user->getId())
            ->where('is_deleted', 0)
            ->where('entity_id', $entity->getId())
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
     * getFollowersBuilder
     * @psalm-suppress MixedReturnStatement
     */
    public static function getFollowersBuilder(EloquentModel $entity, ?AppInterface $app = null): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');
        $socialConnection = config('database.connections.social.database');
        $app = $app ?? app(Apps::class);

        return Users::join($socialConnection . '.users_follows', 'users.id', '=', 'users_follows.users_id')
            ->join($ecosystemConnection . '.users_associated_apps', 'users.id', '=', 'users_associated_apps.users_id')
            ->where($ecosystemConnection . '.users_associated_apps.apps_id', $app->getId())
            ->where($ecosystemConnection . '.users_associated_apps.is_deleted', 0)
            ->where($ecosystemConnection . '.users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users_follows.is_deleted', 0)
            ->where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->groupBy('users.id')
            ->select('users.*');
    }

    /**
     * getFollowingBuilder
     * @psalm-suppress MixedReturnStatement
     */
    public static function getFollowingBuilder(Users $user, ?AppInterface $app = null): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');
        $app = $app ?? app(Apps::class);

        return UsersFollows::where('users_follows.users_id', $user->id)
            ->join($ecosystemConnection . '.users_associated_apps', 'users_follows.users_id', '=', 'users_associated_apps.users_id')
            ->where($ecosystemConnection . '.users_associated_apps.apps_id', $app->getId())
            ->where($ecosystemConnection . '.users_associated_apps.is_deleted', 0)
            ->where($ecosystemConnection . '.users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users_follows.is_deleted', 0);
    }

    /**
     * getTotalFollowers
     * @psalm-suppress MixedReturnStatement
     */
    public static function getTotalFollowers(EloquentModel $entity): int
    {
        return UsersFollows::where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->where('is_deleted', 0)
            ->count();
    }
}
