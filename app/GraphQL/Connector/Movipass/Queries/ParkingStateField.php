<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use Kanvas\Connectors\Movipass\Parking\Support\ParkingStateResolver;
use Kanvas\Souk\Orders\Models\Order;

class ParkingStateField
{
    public function __invoke(Order $order): ?string
    {
        return ParkingStateResolver::for($order)?->value;
    }
}
