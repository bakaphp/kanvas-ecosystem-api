<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Social\Messages\Jobs\DistributeMessagesToUsersJob;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class DistributeMessageActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        DistributeMessagesToUsersJob::dispatch($entity, $app, $params);

        return [
            'message'    => 'Distributed message activity executed',
            'message_id' => $entity->getId(),
            'result'     => true,
        ];
    }
}
