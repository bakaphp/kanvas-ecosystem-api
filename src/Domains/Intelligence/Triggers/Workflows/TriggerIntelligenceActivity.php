<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeAction;
use Kanvas\Intelligence\Triggers\Actions\ApplyLeadAiModeV1Action;
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

                $configService = new LeadConfigurationService();
                $actionClass = $configService->isV2Enabled($lead->company)
                    ? ApplyLeadAiModeAction::class
                    : ApplyLeadAiModeV1Action::class;
                $result = new $actionClass($lead, $triggerType)->execute();
                if ($aiMode = $lead->get($configService->getAiModeKey($lead))) {
                    $this->sendDataToOrchestration($lead, $aiMode);
                }

                return array_merge(['Trigger IA executed'], $result);
            }
        );
    }

    protected function sendDataToOrchestration(Lead $lead, string $aiMode): void
    {
        $data = ['stateDelta' => ['mode' => $aiMode]];
        foreach ($lead->aiSession as $session) {
            $handle = new $session->agent->type->handler();
            $handle->setConfiguration(
                agent: $session->agent,
                entity: $session->entity(),
                user: $lead->company->getAiAgentUserOrFail(),
            );

            try {
                $handle->sendDataToAgent($session->uuid, $data);
            } catch (ClientException $e) {
                if ($e->getResponse()->getStatusCode() === 404) {
                    continue;
                }

                throw $e;
            }
        }
    }
}
