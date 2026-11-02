<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;
use RuntimeException;

/**
 * An activity that does NOT go through `executeIntegration` — the shape of most internal steps —
 * so whatever history it has is written by the engine, not by itself.
 */
#[WorkflowAction(name: 'Test Plain Recording')]
class PlainRecordingActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        if (! empty($params['boom'])) {
            throw new RuntimeException('boom');
        }

        if (! empty($params['soft_fail'])) {
            return $this->failWorkflow(['message' => 'soft fail']);
        }

        return [
            'activity' => static::class,
            'entity_id' => $entity->getId(),
            'workflow_id' => $this->workflowId(),
        ];
    }
}
