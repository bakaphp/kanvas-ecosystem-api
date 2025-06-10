<?php

namespace Kanvas\Connectors\NetSuite\Webhooks\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\NetSuite\Actions\PushOrderToNetSuiteAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class NetsuitePushOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);


        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::NETSUITE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) {
                $pushAction = new PushOrderToNetSuiteAction($app, $order->company);

                $result = $pushAction->execute(
                    order: $order,
                    netsuiteCustomerId: null,
                    createCustomerIfNotExists: false
                );

                return [
                    'order' => $order->getId(),
                    'status' => $result['success'],
                    'message' => $result['message'],
                    'result' => $result['data'],
                ];
            },
            company: $order->company,
        );
    }
}
