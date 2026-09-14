<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\SyncPeopleWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'NetSuite Sync Person',
    description: 'Syncs a person with their NetSuite contact record so the ERP and Kanvas agree on who the '
        . 'buyer is.',
    integration: IntegrationsEnum::NETSUITE,
)]
class SyncPeopleWithNetSuiteActivity extends KanvasActivity
{
    public function execute(People $people, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::NETSUITE,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params) {
                $syncPeopleWithNetSuite = new SyncPeopleWithNetSuiteAction($app, $people->company);
                $company = $syncPeopleWithNetSuite->execute();

                return [
                    'people' => $people->getId(),
                    'net_suite_id' => $people->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value),
                    'name' => $people->name,
                ];
            },
            company: $people->company,
        );
    }
}
