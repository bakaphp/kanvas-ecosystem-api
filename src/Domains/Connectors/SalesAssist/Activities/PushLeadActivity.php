<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
                $connectedCRM = null;

                $result = [];
                if ($isDriveCentric) {
                    $connectedCRM = 'DriveCentric';
                    $result = new PushLeadAction($lead)->execute();
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
