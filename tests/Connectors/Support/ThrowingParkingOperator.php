<?php

declare(strict_types=1);

namespace Tests\Connectors\Support;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Parking\Contracts\ParkingOperator;
use Kanvas\Connectors\Movipass\Parking\DataTransferObject\ParkingQuote;
use Kanvas\Souk\Orders\Models\Order;
use RuntimeException;

class ThrowingParkingOperator implements ParkingOperator
{
    public function quote(Order $order, Carbon $at): ParkingQuote
    {
        throw new RuntimeException('provider down');
    }

    public function confirmPayment(Order $order, string $reference): bool
    {
        throw new RuntimeException('provider down');
    }

    public function authorizeExit(Order $order): bool
    {
        throw new RuntimeException('provider down');
    }
}
