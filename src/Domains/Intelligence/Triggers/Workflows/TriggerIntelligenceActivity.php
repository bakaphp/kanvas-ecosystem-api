<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadStatus;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                // Trigger IA Logic Here
                $triggerType = $params['trigger_type'] ?? null;
                if (! $triggerType) {
                    return $this->failWorkflow([
                        'error' => 'Invalid trigger type',
                    ]);
                }
                $modsPrevious = [
                    'ai_mode' => $lead->get('ai_mode'),
                    'ai_follow_up' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                ];
                switch ($triggerType) {
                    case TriggersEnum::NEW_LEAD->value:
                        $defaultAiMode = $lead->company->get(ConfigurationEnum::AI_MODE->value) ?? IntelligenceModeEnum::FULL_ON->value;
                        $lead->set('ai_mode', $defaultAiMode);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);

                        break;
                    case TriggersEnum::HUMAN_HANDOFF->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

                        break;
                    case TriggersEnum::HUMAN_TAKEOVER->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

                        break;
                    case TriggersEnum::AI_TAKEOVER->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);

                        // Logic for AI takeover trigger
                        break;
                    case TriggersEnum::SOLD_LEAD->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value);

                        // Logic for sold lead trigger
                        break;
                    case TriggersEnum::CLOSE_LEAD->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

                        // Logic for close lead trigger
                        break;
                    case TriggersEnum::MANUAL_OFF->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);
                        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);

                        // Logic for manual off trigger
                        break;
                    case TriggersEnum::MANUAL_SUPPORT->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);

                        // Logic for manual support trigger
                        break;
                    case TriggersEnum::MANUAL_FON->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
                        $status = LeadStatus::getByName('Sold');

                        if ($lead->leads_status_id === $status->getId()) {
                            $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value);
                        } else {
                            $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);
                        }

                        // Logic for manual fon trigger
                        break;
                }

                $modsCurrent = [
                    'ai_mode' => $lead->get('ai_mode'),
                    'ai_follow_up' => $lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value),
                ];
                $this->sentDataToOrchestration($lead, $lead->get('ai_mode'));

                // Post handoff note with state changes

                return [
                    'Trigger IA executed',
                    'mods_previous' => $modsPrevious,
                    'mods_current' => $modsCurrent,
                ];
            }
        );
    }

    protected function sentDataToOrchestration(Lead $lead, string $aiMode): void
    {
        $data = ['stateDelta' => ['mode' => $aiMode]];
        foreach ($lead->aiSession as $session) {
            $handle = new $session->agent->type->handler();
            $handle->setConfiguration($session->agent, $session->entity());
            $handle->sendDataToAgent($session->uuid, $data);
        }
    }
}
