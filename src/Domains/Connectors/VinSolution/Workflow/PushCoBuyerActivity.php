<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intellicheck\Actions\VerifyPeopleIdAction;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Leads\Models\LeadParticipant;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'VinSolution Push Co-Buyer',
    description: 'Pushes a lead PARTICIPANT — the co-buyer — into VinSolutions alongside the main lead, and '
        . 'runs ID verification on them. The verification can NOTIFY the person, so this is not purely '
        . 'a CRM write.',
    integration: IntegrationsEnum::VIN_SOLUTION,
)]
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

        return $this->executeIntegration(
            entity: $participant,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            additionalParams: $params,
            integrationOperation: function ($entity, $app, $integrationCompany, $additionalParams) use ($people, $lead) {
                $lead->reCacheCustomFields();
                $pushLead = new PushLeadAction($lead);

                try {
                    $vinLead = $pushLead->execute();
                } catch (ClientException $e) {
                    if (! ContactRejectionService::isRecordRejection($e)) {
                        throw $e;
                    }

                    return $this->failWorkflow([
                        'error' => 'VinSolution rejected the co-buyer contact information',
                        'reason' => ContactRejectionService::recordForLead($lead, $e),
                        'lead_id' => $lead->getId(),
                        'people_id' => $people->getId(),
                        'company_id' => $lead->companies_id,
                    ]);
                }

                $idVerification = null;
                if ($people->get('intellicheckResponse')) {
                    $idVerification = new VerifyPeopleIdAction(
                        people: $people,
                        lead: $lead
                    )->execute(
                        verificationData: $people->get('intellicheckResponse'),
                        sendNotification: true
                    );
                }

                // Mark as processed
                $lead->set(CustomFieldEnum::LEAD_CO_BUYER_PROCESSED->value, true);

                return [
                    'message' => 'Co-buyer added successfully',
                    'vinLead' => $vinLead->id,
                    'people' => $people->toArray(),
                    'entity' => $entity->toArray(),
                    'lead' => $lead->toArray(),
                    'idVerification' => $idVerification,
                ];
            },
            company: $company,
        );
    }
}
