<?php

declare(strict_types=1);

namespace Kanvas\Social\Repositories;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Models\UsersFollows;

class UsersFollowsRepository
{    
    /**
     * getByUserAndEntity
     *
     * @param  Users $user
     * @param  EloquentModel $entity
     * @return UsersFollows
     */
    public static function getByUserAndEntity(Users $user, EloquentModel $entity): ?UsersFollows
    {
        return UsersFollows::where('users_id', $user->id)
            ->where('entity_id', $entity->id)
            ->where('entity_namespace', get_class($entity))
            ->first();
    }
}
