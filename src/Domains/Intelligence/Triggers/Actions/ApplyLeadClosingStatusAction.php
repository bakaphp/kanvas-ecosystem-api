<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;

class ApplyLeadClosingStatusAction
{
    public function __construct(
        protected Lead $lead,
    ) {
    }

    public function execute(): array
    {
        $statusSuffix = new LeadConfigurationService()->getStatusSuffix($this->lead);

        $triggerType = match ($statusSuffix) {
            'closed-sold' => TriggersEnum::SOLD_LEAD,
            'closed-not-sold' => TriggersEnum::CLOSE_LEAD,
            default => null,
        };

        if ($triggerType === null) {
            return ['Lead pulled, no closing status to apply'];
        }

        $result = new ApplyLeadAiModeAction($this->lead, $triggerType->value)->execute();

        return array_merge(['Lead pulled trigger executed'], $result);
    }
}
