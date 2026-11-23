<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Triggers\Actions\TriggerLeadIntelligenceAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Trigger Intelligence On Lead',
    description: 'Starts the intelligence pipeline for a lead — scoring, enrichment and whatever the app has '
        . 'configured. Entry point rather than an action in itself.',
)]
class TriggerIntelligenceActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app) use ($params) {
                $triggerType = $params['trigger_type'] ?? null;
                if (! $triggerType) {
                    return $this->failWorkflow(['error' => 'Invalid trigger type']);
                }

                $result = new TriggerLeadIntelligenceAction($lead, (int) $triggerType)->execute();

                return array_merge(['Trigger IA executed'], $result);
            }
        );
    }
}
