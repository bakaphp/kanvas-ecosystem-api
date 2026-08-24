<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use GuzzleHttp\Exception\ClientException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushLeadAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Services\ContactRejectionService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'VinSolution Push Lead',
    description: 'Pushes the lead into VinSolutions so the CRM has it. Outbound one-way sync — it writes to '
        . 'VinSolutions and does not bring anything back, and it does not contact the customer. Only '
        . 'useful if this company actually runs VinSolutions; several connectors ship a near-identical '
        . 'step, so pick the one matching the CRM the company uses.',
    integration: IntegrationsEnum::VIN_SOLUTION,
)]
class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $company = $lead->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams): array {
                try {
                    $pushLeadAction = new PushLeadAction(
                        lead: $lead,
                    );

                    $results = $pushLeadAction->execute();
                } catch (ClientException $e) {
                    if ($e->getResponse()?->getStatusCode() === 404) {
                        return $this->failWorkflow([
                            'error' => 'VinSolution assigned user not found',
                            'lead_id' => $lead->getId(),
                            'company_id' => $lead->companies_id,
                        ]);
                    }

                    if (! ContactRejectionService::isRecordRejection($e)) {
                        throw $e;
                    }

                    return $this->failWorkflow([
                        'error' => 'VinSolution rejected the contact information',
                        'reason' => ContactRejectionService::recordForLead($lead, $e),
                        'lead_id' => $lead->getId(),
                        'company_id' => $lead->companies_id,
                    ]);
                }

                return [
                    'message' => 'VinSolution integration completed successfully',
                    'lead' => $results,
                ];
            },
            company: $company,
        );
    }
}
