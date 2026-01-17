<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
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
                switch ($triggerType) {
                    case TriggersEnum::NEW_LEAD->value:
                        $defaultAiMode = $lead->company->get(ConfigurationEnum::AI_MODE->value) ?? IntelligenceModeEnum::FULL_ON->value;
                        $lead->set('ai_mode', $defaultAiMode);
                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 1);

                        break;
                    case TriggersEnum::HUMAN_HANDOFF->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 0);

                        break;
                    case TriggersEnum::HUMAN_TAKEOVER->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(
                            FollowUpTypeEnum::LEAD_FOLLOW_UP->value,
                            0
                        );

                        break;
                    case TriggersEnum::AI_TAKEOVER->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 1);

                        // Logic for AI takeover trigger
                        break;
                    case TriggersEnum::SOLD_LEAD->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);
                        $lead->set(FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value, 1);

                        // Logic for sold lead trigger
                        break;
                    case TriggersEnum::CLOSE_LEAD->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 0);

                        // Logic for close lead trigger
                        break;
                    case TriggersEnum::MANUAL_OFF->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::OFF->value);
                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 0);
                        $lead->set(FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value, 0);

                        // Logic for manual off trigger
                        break;
                    case TriggersEnum::MANUAL_SUPPORT->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);

                        // Logic for manual support trigger
                        break;
                    case TriggersEnum::MANUAL_FON->value:
                        $lead->set('ai_mode', IntelligenceModeEnum::FULL_ON->value);

                        $lead->set(FollowUpTypeEnum::LEAD_FOLLOW_UP->value, 1);
                        $lead->set(FollowUpTypeEnum::SOLD_LEAD_FOLLOW_UP->value, 1);

                        // Logic for manual fon trigger
                        break;
                }

                return ['Trigger IA executed'];
            }
        );
    }
}
