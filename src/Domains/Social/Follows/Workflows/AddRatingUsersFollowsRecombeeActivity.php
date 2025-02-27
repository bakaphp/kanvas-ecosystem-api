<?php

declare(strict_types=1);

namespace Kanvas\Social\Follows\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Recombee\Actions\AddRatingUserItemAction;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Override;
use Workflow\Activity;

class AddRatingUsersFollowsRecombeeActivity extends Activity implements WorkflowActivityInterface
{
    use KanvasJobsTrait;
    //public $tries = 3;

    /**
     * @param \Kanvas\Social\Interactions\Models\UsersInteractions $entity
     */
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);
        $user = Users::getById($entity->users_id);

        $rating = $entity->is_deleted ? -1.0 : 1.0;

        (new AddRatingUserItemAction(
            $app,
            $user,
            (int) $entity->entity_id,
            $rating
        ))->execute();

        return [
            'result' => true,
            'message' => "Rating Added successfully from user {$user->getId()} that follows" . $entity->entity_id,
            'entity' => [
                get_class($entity),
                $entity->getId(),
            ],
        ];
    }
}
