<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SetLeadAiModeOffIfEmailOnlyActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($lead) {
                $people = $lead->people;

                if (! $people) {
                    return $this->failWorkflow([
                        'message' => 'Lead does not have an associated person',
                        'lead_id' => $lead->getId(),
                    ]);
                }

                $hasEmail = $people->getEmails()->isNotEmpty();
                $hasPhone = $people->getPhones()->isNotEmpty();
                $hasCellPhone = $people->getCellPhones()->isNotEmpty();

                if ($hasEmail && ! $hasPhone && ! $hasCellPhone) {
                    $lead->set(ConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::OFF->value);

                    return [
                        'success' => true,
                        'message' => 'Lead AI mode set to OFF (email only contact)',
                        'lead_id' => $lead->getId(),
                        'ai_mode' => IntelligenceModeEnum::OFF->value,
                    ];
                }

                return [
                    'success' => true,
                    'message' => 'Lead has phone or cellphone, AI mode unchanged',
                    'lead_id' => $lead->getId(),
                    'has_email' => $hasEmail,
                    'has_phone' => $hasPhone,
                    'has_cellphone' => $hasCellPhone,
                ];
            },
            company: $lead->company,
        );
    }
}
