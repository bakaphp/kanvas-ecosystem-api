<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SaveOrderToIPlusAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\KanvasActivity;

class SyncOrderWithIPlusActivities extends KanvasActivity
{
    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $createOrder = new SaveOrderToIPlusAction($order);
        $response = $createOrder->execute();

        return [
            'status' => 'success',
            'message' => 'Order synced with IPlus',
            'response' => $response,
        ];
    }
}
