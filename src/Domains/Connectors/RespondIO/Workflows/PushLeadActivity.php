<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\RespondIO\Actions\PushLeadAction;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'RespondIO Push Lead',
    description: 'Pushes the lead into Respond.io so the CRM has it. Outbound one-way sync — it writes to '
        . 'Respond.io and does not bring anything back, and it does not contact the customer. Only '
        . 'useful if this company actually runs Respond.io; several connectors ship a near-identical '
        . 'step, so pick the one matching the CRM the company uses.',
    integration: IntegrationsEnum::RESPOND_IO,
)]
class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $company = $lead->company;

        $bearerToken = $company->get(ConfigurationEnum::BEARER_TOKEN->value)
            ?? $app->get(ConfigurationEnum::BEARER_TOKEN->value);

        if (! $bearerToken) {
            return [
                'error' => 'Respond.io bearer token not configured for this company or app',
            ];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::RESPOND_IO,
            additionalParams: $params,
            integrationOperation: function (Lead $lead): array {
                $response = new PushLeadAction(lead: $lead)->execute();

                return [
                    'message' => 'Lead pushed to Respond.io successfully',
                    'contact' => $response,
                ];
            },
            company: $company,
        );
    }
}
