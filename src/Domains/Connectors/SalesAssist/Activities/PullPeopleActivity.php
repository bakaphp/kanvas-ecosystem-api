<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PullPeopleActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    /**
     * $entity <People>.
     */
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        $peopleId = $params['entity_id'] ?? null;

        $people = $entity;
        if ($people === null) {
            return [
                'result'  => false,
                'message' => 'People not found with id '.$peopleId,
            ];
        }

        return $people->toArray();
    }
}
