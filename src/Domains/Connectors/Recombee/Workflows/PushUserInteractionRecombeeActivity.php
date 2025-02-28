<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Workflows;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Recombee\Services\RecombeeInteractionService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Override;
use Workflow\Activity;

class PushUserInteractionRecombeeActivity extends Activity implements WorkflowActivityInterface
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

        $recombeeInteractionService = new RecombeeInteractionService($app);
        $recombeeInteractionService->addUserInteraction($entity);

        return [
            'result' => true,
            'message' => 'User interaction added ID ' . $entity->getId(),
            'entity' => [
                get_class($entity),
                $entity->getId(),
            ],
        ];
    }
}
