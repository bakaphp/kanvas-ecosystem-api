<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'DriveCentric Push Lead',
    description: 'Pushes the lead into DriveCentric so the CRM has it. Outbound one-way sync — it writes to '
        . 'DriveCentric and does not bring anything back, and it does not contact the customer. Only '
        . 'useful if this company actually runs DriveCentric; several connectors ship a near-identical '
        . 'step, so pick the one matching the CRM the company uses.',
    integration: IntegrationsEnum::DRIVE_CENTRIC,
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
            integration: IntegrationsEnum::DRIVE_CENTRIC,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pushAction = new PushLeadAction($lead);
                $result = $pushAction->execute();

                return [
                    'message' => 'Lead pushed successfully to DriveCentric',
                    'entity' => $result,
                ];
            },
            company: $lead->company,
        );
    }
}
