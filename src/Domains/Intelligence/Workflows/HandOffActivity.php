<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class HandOffActivity extends KanvasActivity
{
    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params) {
                $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);

                try {
                    if ($rotation = LeadRotation::getById($params['rotation_id'], $app)) {
                        $leadOwner = $rotation->getAgent();
                        $lead->leads_owner_id = $leadOwner->getId();
                        $lead->saveOrFail();
                    }
                } catch (Exception $e) {
                    $leadOwner = $lead->owner;
                }

                $leadOwner->notify(
                    new HandOffNotification(
                        lead: $lead,
                        templateName: $params['template_name'] ?? 'lead_handoff',
                        data: [
                                'lead' => $lead,
                                'agent' => $leadOwner,
                            ]
                    )
                );
            }
        );
    }
}
