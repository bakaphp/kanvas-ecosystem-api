<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Reynolds\Actions\PushLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum;
use Kanvas\Connectors\Reynolds\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction]
class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $company = $lead->company;

        if (empty($company->get(ConfigurationEnum::REYNOLDS_ENDPOINT->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_USERNAME->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_PASSWORD->value))
        ) {
            return ['error' => 'Reynolds credentials are not configured for this company'];
        }

        if (empty($company->get(ConfigurationEnum::REYNOLDS_DEALER_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_STORE_NUMBER->value))
            || empty($company->get(ConfigurationEnum::REYNOLDS_AREA_NUMBER->value))
        ) {
            return ['error' => 'Reynolds dealer/store/area not configured for this company'];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::REYNOLDS,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, Apps $app, mixed $integrationCompany, array $additionalParams): array {
                if ($lead->get(CustomFieldEnum::PROSPECT_ID->value)) {
                    return [
                        'message' => 'Lead already exists in Reynolds — skipping push',
                        'prospect_id' => (string) $lead->get(CustomFieldEnum::PROSPECT_ID->value),
                        'lead_id' => $lead->getId(),
                    ];
                }

                try {
                    $result = new PushLeadAction($lead)->execute();
                } catch (Throwable $e) {
                    return $this->failWorkflow([
                        'error' => 'Reynolds Insert Sales Lead failed: ' . $e->getMessage(),
                    ]);
                }

                return [
                    'message' => 'Lead pushed to Reynolds successfully',
                    'prospect_id' => $result['prospect_id'],
                    'lead_id' => $lead->getId(),
                ];
            },
            company: $lead->company,
        );
    }
}
