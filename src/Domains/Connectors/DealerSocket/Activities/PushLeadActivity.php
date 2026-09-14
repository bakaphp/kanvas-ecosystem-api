<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Actions\PushLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'DealerSocket Push Lead',
    description: 'Pushes the lead into DealerSocket so the CRM has it. Outbound one-way sync — it writes to '
        . 'DealerSocket and does not bring anything back, and it does not contact the customer. Only '
        . 'useful if this company actually runs DealerSocket; several connectors ship a near-identical '
        . 'step, so pick the one matching the CRM the company uses.',
    integration: IntegrationsEnum::DEALERSOCKET,
)]
class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::DEALERSOCKET,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                $data = new PushLeadAction(
                    lead: $lead
                )->execute();

                return [
                    'message' => 'Lead pushed successfully',
                    //'entity' => $pushLead,
                    'data' => $data,
                ];
            },
            company: $lead->company,
        );
    }
}
