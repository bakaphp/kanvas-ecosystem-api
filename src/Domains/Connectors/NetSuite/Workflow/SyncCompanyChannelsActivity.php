<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetsuiteCustomerItemChannels;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SyncCompanyChannelsActivity extends KanvasActivity
{
    public function execute(Companies $buyerCompany, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $buyerCompany,
            app: $app,
            integration: IntegrationsEnum::NETSUITE,
            additionalParams: $params,
            integrationOperation: function ($buyerCompany, $app, $integrationCompany, $additionalParams) {
                $syncChannels = new SyncNetsuiteCustomerItemChannels(
                    company: $app->getAppCompany(),
                    buyerCompany: $buyerCompany,
                );

                $result = $syncChannels->execute();

                return [
                    'success' => true,
                    'company_id' => $buyerCompany->getId(),
                    'company_name' => $buyerCompany->name,
                    'action' => $result['action'],
                    'channels_affected' => $result['channel'],
                    'message' => "Channel {$result['action']} for company {$buyerCompany->name}",
                ];
            },
            company: $buyerCompany,
        );
    }
}
