<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SaveOrderToIPlusAction;
use Kanvas\Connectors\IPlus\Actions\SavePeopleToIPlusAction;
use Kanvas\Connectors\IPlus\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SyncOrderWithIPlusActivities extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::IPLUS,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                /*    $createOrder = new SaveOrderToIPlusAction($order);
                   $response = $createOrder->execute(); */

                if ($order->get(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value)) {
                    return [
                        'status' => 'success',
                        'message' => 'Order already synced with IPlus',
                        'response' => $order->get(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value),
                    ];
                }

                $createPeopleAction = new SavePeopleToIPlusAction($order->people);
                $responseClientId = $createPeopleAction->execute();

                $order->set(CustomFieldEnum::I_PLUS_CUSTOMER_ID->value, $responseClientId);

                return [
                    'status' => 'success',
                    'message' => 'Order synced with IPlus Customer',
                    'response' => $responseClientId,
                ];
            },
            company: $order->company,
        );
    }
}
