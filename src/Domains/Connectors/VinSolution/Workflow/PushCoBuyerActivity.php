<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\KanvasActivity;

class PushCoBuyerActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(LeadParticipant $participant, Apps $app, array $params): array
    {
        $company = $participant->people->company;
        $lead = $participant->lead;
        $people = $participant->people;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        if ($lead->get(CustomFieldEnum::LEAD_CO_BUYER_PROCESSED->value)) {
            return [
                'error' => 'Co-buyer already processed',
            ];
        }

        $pushLead = new PushLeadAction($lead);
        $vinLead = $pushLead->execute();

        return [
            'message' => 'Co-buyer added successfully',
            'vinLead' => $vinLead->id,
            'people' => $people->toArray(),
            'lead' => $lead->toArray(),
        ];
    }
}
