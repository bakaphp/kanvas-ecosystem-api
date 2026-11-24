<?php

declare(strict_types=1);

namespace Kanvas\Guild\Followers\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Followers\Models\Follower;
use Kanvas\Guild\Followers\Repositories\FollowersRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Repositories\UsersRepository;

class AddFollowerAction
{
    public function __construct(
        protected Lead|People $entity,
        protected Users $user,
    ) {
    }

    /**
     * @throws ModelNotFoundException when the user is not a member of the record's company
     */
    public function execute(): Follower
    {
        UsersRepository::belongsToThisApp($this->user, $this->entity->app, $this->entity->company);

        try {
            return $this->follow();
        } catch (UniqueConstraintViolationException) {
            // Two messages from the same person landed together; the other one wrote the row.
            return $this->follow();
        }
    }

    private function follow(): Follower
    {
        $follower = FollowersRepository::getByUserAndEntity($this->user, $this->entity, withDeleted: true);

        if (! $follower instanceof Follower) {
            $follower = new Follower();
            $follower->entity_namespace = FollowersRepository::namespaceFor($this->entity);
            $follower->entity_id = $this->entity->getId();
            $follower->users_id = $this->user->getId();
        }

        $follower->apps_id = $this->entity->apps_id;
        $follower->companies_id = $this->entity->companies_id;
        $follower->is_deleted = 0;
        $follower->saveOrFail();

        return $follower;
    }
}
