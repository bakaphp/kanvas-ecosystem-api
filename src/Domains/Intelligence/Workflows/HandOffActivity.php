<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Notifications\HandOffNotification;
use Kanvas\Workflow\KanvasActivity;

class HandOffActivity extends KanvasActivity
{
    public function execute(Lead $lead, Apps $app, array $params): void
    {
        $this->overwriteAppService($app);
        $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
        if ($rotation = LeadRotation::getById($params['rotation_id'], $app)) {
            $leadOwner = $rotation->getAgent();
            $lead->leads_owner_id = $leadOwner->getId();
            $lead->saveOrFail();
            $leadOwner->notify(
                new HandOffNotification(
                    lead: $lead,
                    templateName: $params['template_name'],
                    data: [
                        'lead' => $lead,
                        'agent' => $leadOwner,
                    ]
                )
            );
        }
    }
}
