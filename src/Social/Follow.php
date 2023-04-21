<?php
declare(strict_types=1);
namespace Kanvas\Social;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Users\Models\Users;
use Kanvas\Social\Models\UsersFollows;
use Kanvas\Social\Repositories\UsersFollowsRepository;
class Follow {
    
    /**
     * follow
     *
     * @param  mixed $user
     * @param  mixed $entity
     * @return UsersFollows
     */
    public static function follow(Users $user, EloquentModel $entity): UsersFollows
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($user, $entity);
        if (!$follow) {
            $follow = new UsersFollows();
            $follow->users_id = $user->getId();
            $follow->entity_id = $entity->getId();
            $follow->entity_namespace = get_class($entity);
            $follow->saveOrFail();
        }else{
            self::unfollow($user, $entity);
        }
        return $follow;
    }
    
    /**
     * unFollow
     *
     * @param  Users $user
     * @param  EloquentModel $entity
     * @return bool
     */
    public static function unFollow(Users $user, EloquentModel $entity): bool
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($user, $entity);
        if ($follow) {
            $follow->delete();
        }
        return true;
    }
    
    /**
     * isFollowing
     *
     * @param  Users $user
     * @param  EloquentModel $entity
     * @return bool
     */
    public static function isFollowing(Users $user, EloquentModel $entity): bool
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($user, $entity);
        if ($follow) {
            return true;
        }
        return false;
    }
}