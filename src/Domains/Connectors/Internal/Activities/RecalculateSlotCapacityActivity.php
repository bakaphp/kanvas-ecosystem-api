<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Actions\RecalculateSlotCapacityAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class RecalculateSlotCapacityActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        $checkExpiredOrders = $app->get(ConfigurationEnum::CHECK_EXPIRED_ORDERS->value) == 1;

        if ($checkExpiredOrders) {
            /** @var Order $order */
            new RecalculateSlotCapacityAction($order, $app)->execute();
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Warehouse quantity calculated',
        ];
    }
}
