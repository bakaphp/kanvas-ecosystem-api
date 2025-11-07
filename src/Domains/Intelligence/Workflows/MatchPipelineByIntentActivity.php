<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Intelligence\Tools\LeadIntentTool;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class MatchPipelineByIntentActivity extends KanvasActivity
{
    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $leadIntent = new LeadIntentTool($lead)->execute();
                $key = $leadIntent['lead_intent'];
                $config = $params['match_pipeline'] ?? [];
                $channel = $lead->get(ConfigurationEnum::AGENT_COMMUNICATION_CHANNEL->value);
                $pipelineId = $config[$key][$channel] ?? $config['default'][$channel] ?? null;
                $pipeline = Pipeline::getById($pipelineId);
                $lead->pipeline_id = $pipelineId;
                $lead->pipeline_stage_id = $pipeline->stages()->orderBy('weight')->first()?->id;
                $lead->saveOrFail();

                return [
                    'pipeline' => $pipelineId,
                    'stage' => $lead->pipeline_stage_id,
                    'lead_intent' => $leadIntent,
                ];
            }
        );
    }
}
