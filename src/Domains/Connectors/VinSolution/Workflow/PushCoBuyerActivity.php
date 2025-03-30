<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Workflow\Traits\ActivityIntegrationTrait;

class PushCoBuyerActivity extends KanvasActivity
{
    use ActivityIntegrationTrait;
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

        return $this->executeIntegration(
            entity: $participant,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($people, $lead) {
                if ($lead->get(CustomFieldEnum::LEAD_CO_BUYER_PROCESSED->value)) {
                    return [
                        'error' => 'Co-buyer already processed',
                    ];
                }

                $pushLead = new PushLeadAction($lead);
                $vinLead = $pushLead->execute();

                // Mark as processed
                $lead->set(CustomFieldEnum::LEAD_CO_BUYER_PROCESSED->value, true);

                return [
                    'message' => 'Co-buyer added successfully',
                    'vinLead' => $vinLead->id,
                    'people' => $people->toArray(),
                    'entity' => $entity->toArray(),
                    'lead' => $lead->toArray(),
                ];
            },
            company: $company,
        );
    }
}
