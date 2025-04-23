<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Users\Models\Users;

class UsersFollowsRepository
{
    public static function isFollowing(Users $user, EloquentModel $entity, ?AppInterface $app = null): bool
    {
        return (bool) self::getByUserAndEntity($user, $entity, $app);
    }

    public static function getUserFollowerBuilder(UserInterface $user, AppInterface $app): Builder
    {
        return self::getFollowersBuilder($user, $app);
    }

    public static function getUserFollowingBuilder(Users $user, ?AppInterface $app = null): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');
        $socialConnection = config('database.connections.social.database');
        $app = $app ?? app(Apps::class);

        return Users::join($socialConnection.'.users_follows', 'users.id', '=', 'users_follows.entity_id')
                ->where('users_follows.apps_id', $app->getId())
                ->where('users_follows.is_deleted', 0)
                ->where('users_follows.users_id', $user->id)
                ->where('users_follows.entity_id', '!=', $user->id)
               // ->where('users_follows.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('users_follows.entity_namespace', Users::class)
                ->select('users.*');
    }

    public static function getByUserAndEntity(Users $user, EloquentModel $entity, ?AppInterface $apps = null): ?UsersFollows
    {
        $apps = $apps ?? app(Apps::class);

        return UsersFollows::where('users_id', $user->getId())
            ->where('is_deleted', 0)
            ->where('entity_id', $entity->getId())
            ->where('entity_namespace', get_class($entity))
            ->where('apps_id', $apps->getId())
            ->first();
    }

    public static function getFollowersBuilder(EloquentModel $entity, ?AppInterface $app = null): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');
        $socialConnection = config('database.connections.social.database');
        $app = $app ?? app(Apps::class);

        return Users::join($socialConnection.'.users_follows', 'users.id', '=', 'users_follows.users_id')
                ->where('users_follows.apps_id', $app->getId())
                ->where('users_follows.is_deleted', 0)
                ->where('users_follows.entity_id', $entity->id)
                ->where('users_follows.users_id', '!=', $entity->id)
               // ->where('users_follows.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                ->where('users_follows.entity_namespace', Users::class)
                ->select('users.*');
    }

    public static function getFollowingBuilder(Users $user, ?AppInterface $app = null): Builder
    {
        $ecosystemConnection = config('database.connections.ecosystem.database');
        $app = $app ?? app(Apps::class);

        return UsersFollows::where('users_follows.users_id', $user->id)
            ->join($ecosystemConnection.'.users_associated_apps', 'users_follows.users_id', '=', 'users_associated_apps.users_id')
            ->where($ecosystemConnection.'.users_associated_apps.apps_id', $app->getId())
            ->where($ecosystemConnection.'.users_associated_apps.is_deleted', 0)
            ->where($ecosystemConnection.'.users_associated_apps.companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
            ->where('users_follows.is_deleted', 0);
    }

    public static function getTotalFollowers(EloquentModel $entity): int
    {
        return UsersFollows::where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->where('is_deleted', 0)
            ->count();
    }
}
