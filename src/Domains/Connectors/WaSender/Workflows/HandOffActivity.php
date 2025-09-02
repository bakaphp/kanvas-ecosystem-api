<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadRotation;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Workflow\KanvasActivity;

class HandOffActivity extends KanvasActivity
{
    public function execute(Lead $lead, Apps $app, array $params): void
    {
        $this->overwriteAppService($app);
        $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
        if ($rotation = LeadRotation::getById($params['rotation_id'], $app)) {
            $lead->leads_owner_id = $rotation->getAgent();
            $lead->saveOrFail();
        }
    }
}
