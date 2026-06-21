<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class ApplyLeadClosingStatusActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app) {
                $statusSuffix = new LeadConfigurationService()->getStatusSuffix($lead);

                $triggerType = match ($statusSuffix) {
                    'closed-sold' => TriggersEnum::SOLD_LEAD,
                    'closed-not-sold' => TriggersEnum::CLOSE_LEAD,
                    default => null,
                };

                if ($triggerType === null) {
                    return ['Lead pulled, no closing status to apply'];
                }

                $result = new ApplyLeadAiModeAction($lead, $triggerType->value)->execute();

                return array_merge(['Lead pulled trigger executed'], $result);
            }
        );
    }
}
