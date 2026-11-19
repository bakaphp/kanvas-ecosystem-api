<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Concerns;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

trait ResolvesRegionalRoadsideOrder
{
    /**
     * The cabin and the technicians work orders from every customer company, so the lookup is
     * app-scoped; who may act on it is decided by the schema guard and the action's own checks.
     */
    protected function regionalRoadsideOrder(AppInterface $app, int|string $orderId): Order
    {
        $order = Order::getById((int) $orderId, $app);

        if (! RegionalAssistance::isRegionalCase($order)) {
            throw new ValidationException('This order is not an Asistencia Regional roadside assistance case');
        }

        return $order;
    }
}
