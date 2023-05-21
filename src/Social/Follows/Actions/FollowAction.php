<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Actions;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class FollowAction
{
    public function __construct(
        public Users $user,
        public EloquentModel $entity
    ) {
    }

    public function execute(): UsersFollows
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($this->user, $this->entity);

        if ($follow) {
            return $follow;
        }

        $follow = new UsersFollows();
        $follow->users_id = $this->user->getId();
        $follow->entity_id = $this->entity->getId();
        $follow->entity_namespace = get_class($this->entity);
        $follow->saveOrFail();

        return $follow;
    }
}
