<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Reynolds\Actions\PushLeadAction as ReynoldsPushLeadAction;
use Kanvas\Connectors\Reynolds\Enums\ConfigurationEnum as ReynoldsConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Throwable;

#[WorkflowAction(
    name: 'SalesAssist Push Lead',
    description: 'Pushes the lead into the SalesAssist legacy CRM so the CRM has it. Outbound one-way sync — '
        . 'it writes to the SalesAssist legacy CRM and does not bring anything back, and it does not '
        . 'contact the customer. Only useful if this company actually runs the SalesAssist legacy CRM; '
        . 'several connectors ship a near-identical step, so pick the one matching the CRM the company '
        . 'uses.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class PushLeadActivity extends KanvasActivity
{
    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $useGlobalWorkflows = $lead->company->get('use_global_workflows') ?? false;
        if (! $useGlobalWorkflows) {
            return ['Company is not configure to use global workflows'];
        }

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Lead $lead, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $company = $lead->company;

                $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
                $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;
                $isReynolds = $company->get(ReynoldsConfigurationEnum::REYNOLDS_DEALER_NUMBER->value) !== null;
                $connectedCRM = null;

                $result = [];
                if ($isDriveCentric) {
                    $connectedCRM = 'DriveCentric';
                    $result = new PushLeadAction($lead)->execute();
                } elseif ($isReynolds) {
                    $connectedCRM = 'Reynolds';

                    // ReynoldsPushLeadAction upserts internally: it delegates to
                    // UpdateLeadAction (USL) when the lead already carries a
                    // REYNOLDS_PROSPECT_ID, else runs the ISL insert path.
                    try {
                        $result = new ReynoldsPushLeadAction($lead)->execute();
                    } catch (Throwable $e) {
                        return $this->failWorkflow([
                            'error' => 'Reynolds push failed: ' . $e->getMessage(),
                            'crm' => $connectedCRM,
                        ]);
                    }
                }

                return [
                    'message' => 'Lead pushed successfully',
                    'crm' => $connectedCRM,
                    'entity' => $result,
                ];
            },
            company: $lead->company,
        );
    }
}
