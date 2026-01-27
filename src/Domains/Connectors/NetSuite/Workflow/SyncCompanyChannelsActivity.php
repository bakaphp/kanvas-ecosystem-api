<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\SyncNetsuiteCustomerItemChannels;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Enums\IntegrationsEnum;

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
                    company: $app->getMainCompany(),
                    buyerCompany: $buyerCompany,
                );

                $result = $syncChannels->execute();

                return [
                    'success' => true,
                    'company_id' => $buyerCompany->getId(),
                    'company_name' => $buyerCompany->name,
                    'action' => $result['action'],
                    'channels_affected' => $result['channel'],
                    'message' => sprintf(
                        '%d channel(s) %s for company %s',
                        $result['channels_affected'],
                        $result['action'],
                        $buyerCompany->name
                    ),
                ];
            },
            company: $buyerCompany,
        );


    }
}
