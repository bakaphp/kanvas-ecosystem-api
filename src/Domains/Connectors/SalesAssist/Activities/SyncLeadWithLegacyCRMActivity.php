<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\SalesAssist\Actions\SyncLeadWithLegacyCRMAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Sync Lead With Legacy CRM',
    description: 'Pushes the lead into the legacy SalesAssist CRM so both sides agree. Outbound sync.',
)]
class SyncLeadWithLegacyCRMActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams): array {
                $action = new SyncLeadWithLegacyCRMAction($lead);

                $result = $action->execute();

                return $result['success'] ? $result : $this->failWorkflow($result);
            },
            company: $lead->company,
        );
    }
}
