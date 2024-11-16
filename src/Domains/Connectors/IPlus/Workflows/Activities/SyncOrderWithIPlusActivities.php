<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Workflows\Activities;

use Baka\Traits\KanvasJobsTrait;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SyncOrderWithIPlusAction;
use Kanvas\Souk\Orders\Models\Order;
use Workflow\Activity;

class SyncOrderWithIPlusActivities extends Activity
{
    use KanvasJobsTrait;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $createOrder = new SyncOrderWithIPlusAction($order);
        $response = $createOrder->execute();

        return [
            'status' => 'success',
            'message' => 'Order synced with IPlus',
            'response' => $response,
        ];
    }
}
