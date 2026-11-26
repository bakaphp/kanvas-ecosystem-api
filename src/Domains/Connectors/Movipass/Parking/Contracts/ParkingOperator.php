<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Contracts;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Parking\DataTransferObject\ParkingQuote;
use Kanvas\Souk\Orders\Models\Order;

interface ParkingOperator
{
    public function quote(Order $order, Carbon $at): ParkingQuote;

    public function confirmPayment(Order $order, string $reference): bool;

    public function authorizeExit(Order $order): bool;
}
