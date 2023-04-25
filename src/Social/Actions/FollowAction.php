<?php

declare(strict_types=1);

namespace Kanvas\Social\Actions;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Social\Models\UsersFollows;
use Kanvas\Social\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class FollowAction
{
    public function __construct(public Users $user, public EloquentModel $entity)
    {
    }

    public function execute()
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($this->user, $this->entity);

        if (! $follow) {
            $follow = new UsersFollows();
            $follow->users_id = $this->user->getId();
            $follow->entity_id = $this->entity->getId();
            $follow->entity_namespace = get_class($this->entity);
            $follow->saveOrFail();
        } else {
            (new UnFollowAction($this->user, $this->entity))->execute();
        }

        return $follow;
    }
}
