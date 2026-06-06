<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ProductEnrichment\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ProductEnrichment\Actions\EnrichProductAction;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * `agent_id` in the rule params picks the (app-scoped) enrichment agent; omit it for the app default.
 */
#[WorkflowAction]
class EnrichProductActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Products $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::PRODUCT_ENRICHMENT,
            integrationOperation: fn () => new EnrichProductAction(
                $entity,
                isset($params['agent_id']) ? (int) $params['agent_id'] : null,
            )->execute(),
            additionalParams: $params,
            company: $entity->company,
        );
    }
}
