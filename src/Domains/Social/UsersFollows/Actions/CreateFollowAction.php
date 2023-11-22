<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersFollows\Actions;

use Kanvas\Users\Models\Users;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Companies\Models\Companies;

class CreateFollowAction
{
    public function __construct(
        private Users $user,
        private object $entity,
        private Companies $company
    ) {
    }

    public function execute(): UsersFollows
    {
        $userFollow = new UsersFollows();
        $userFollow->users_id = $this->user->getId();
        $userFollow->entity_id = $this->entity->getId();
        $userFollow->companies_id = $this->company->getId();
        $userFollow->companies_branches_id = $this->company->defaultBranch()->firstOrFail()->getId();
        $userFollow->entity_namespace = $this->entity::class;
        $userFollow->created_at = date('Y-m-d H:i:s');
        $userFollow->is_deleted = 0;
        $userFollow->saveOrFail();

        return $userFollow;
    }
}
