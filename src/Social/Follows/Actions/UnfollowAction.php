<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Actions;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Users\Models\Users;

class UnFollowAction
{
    public function __construct(
        public Users $user,
        public EloquentModel $entity
    ) {
    }

    public function execute(): bool
    {
        $follow = UsersFollowsRepository::getByUserAndEntity($this->user, $this->entity);
        if ($follow) {
            $follow->delete();
        }

        return true;
    }
}
