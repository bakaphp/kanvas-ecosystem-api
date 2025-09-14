<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\ActionEngine\Pipelines\Models\Pipeline;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Leads\Actions\CreateLeadContextInfoAction;
use Kanvas\Intelligence\Leads\Actions\CreateLeadFirstEngagementMessageAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class LeadAgentFirstInteractionActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $createContext = new CreateLeadContextInfoAction($lead)->execute($params);

                //get the first message
                $firstLeadMessage = new CreateLeadFirstEngagementMessageAction($lead)->execute();

                //send the first message

                //move to stage 2 of the pipeline
                $lead->moveToNextPipelineStage();

                return [
                    'context' => $createContext,
                    'first_message' => $firstLeadMessage,
                ];
            }
        );
    }
}
