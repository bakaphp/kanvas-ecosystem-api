<?php

declare(strict_types=1);

namespace Kanvas\Guild\Followers\Repositories;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class FollowersRepository
{
    /**
     * The base class, never `$entity::class`: a subclass of Lead or People would otherwise file its
     * follows under a second namespace and the same record would have two follower lists.
     *
     * @return class-string<Lead|People>
     */
    public static function namespaceFor(Lead|People $entity): string
    {
        return $entity instanceof Lead ? Lead::class : People::class;
    }

    public static function forEntity(Lead|People $entity): Builder
    {
        return Follower::query()
            ->where('entity_namespace', self::namespaceFor($entity))
            ->where('entity_id', $entity->getId());
    }

    /**
     * Includes an unfollowed row, so following again restores it instead of hitting the unique key.
     */
    public static function getByUserAndEntity(
        UserInterface $user,
        Lead|People $entity,
        bool $withDeleted = false
    ): ?Follower {
        return self::forEntity($entity)
            ->where('users_id', $user->getId())
            ->when(! $withDeleted, fn (Builder $query) => $query->where('is_deleted', 0))
            ->first();
    }

    public static function isFollowing(UserInterface $user, Lead|People $entity): bool
    {
        return self::getByUserAndEntity($user, $entity) instanceof Follower;
    }

    /**
     * For a merge. A user on both records ends up with one row on the target, and an active follow
     * wins over an unfollowed one, whichever record it was on.
     */
    public static function moveFollowers(Lead|People $source, Lead|People $target): int
    {
        self::forEntity($target)
            ->where('is_deleted', 1)
            ->whereIn('users_id', self::forEntity($source)->where('is_deleted', 0)->pluck('users_id'))
            ->delete();

        self::forEntity($source)
            ->whereIn('users_id', self::forEntity($target)->pluck('users_id'))
            ->delete();

        return self::forEntity($source)->update(['entity_id' => $target->getId()]);
    }

    /**
     * Users live in `ecosystem` and followers in `crm`, hence the database-qualified join.
     */
    public static function getFollowersBuilder(Lead|People $entity): Builder
    {
        $table = new Follower()->getTable();
        $qualifiedTable = config('database.connections.crm.database') . '.' . $table;

        return Users::join($qualifiedTable, 'users.id', '=', $table . '.users_id')
            ->where($table . '.entity_namespace', self::namespaceFor($entity))
            ->where($table . '.entity_id', $entity->getId())
            ->where($table . '.is_deleted', 0)
            ->select('users.*');
    }
}
