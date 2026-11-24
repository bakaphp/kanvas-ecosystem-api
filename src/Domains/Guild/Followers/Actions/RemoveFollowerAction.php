<?php

declare(strict_types=1);

namespace Kanvas\Guild\Followers\Actions;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Followers\Repositories\FollowersRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;

class RemoveFollowerAction
{
    public function __construct(
        protected Lead|People $entity,
        protected Users $user,
    ) {
    }

    public function execute(): bool
    {
        $follower = FollowersRepository::getByUserAndEntity($this->user, $this->entity);

        return $follower instanceof Follower && $follower->softDelete();
    }
}
