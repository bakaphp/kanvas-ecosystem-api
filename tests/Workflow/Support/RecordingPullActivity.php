<?php

declare(strict_types=1);

namespace Tests\Workflow\Support;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Stands in for a connector's pull activity: records what it was handed and goes through
 * `executeIntegration` so a history row is written the way a real connector would.
 */
#[WorkflowAction(name: 'Test Recording Pull', integration: IntegrationsEnum::INTERNAL)]
class RecordingPullActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $entity, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: fn ($entity, $app, $integrationCompany, $additionalParams) => [
                'activity' => static::class,
                'entity_id' => $entity->getId(),
                'workflow_id' => $this->workflowId(),
                'pulled_id' => $additionalParams['entity_id'] ?? null,
            ],
            company: Companies::getById($entity->companies_id),
        );
    }
}
