<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Workflow;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\NetSuite\Actions\PushOrderToNetSuiteQuoteAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class PushOrderToNetsuiteActivity extends KanvasActivity implements WorkflowActivityInterface
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
                $netsuiteCustomerId = $order->user->getCurrentCompany()->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value) ?? null;

                $result = new PushOrderToNetSuiteQuoteAction($app, $order->company)
                        ->execute(
                            order: $order,
                            netsuiteCustomerId: $netsuiteCustomerId !== null ? (string) $netsuiteCustomerId : null,
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
