<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Actions;

use Kanvas\Connectors\Movipass\Jobs\ConfirmParkingPaymentJob;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Souk\Orders\Models\Order;

class DispatchParkingPaymentConfirmationAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): bool
    {
        if (
            ! ParkingMetadata::hasEntered($this->order)
            || ParkingMetadata::isPaymentConfirmed($this->order)
            || ! ParkingQuotePayment::isPaid($this->order)
        ) {
            return false;
        }

        ConfirmParkingPaymentJob::dispatch($this->order->app, $this->order)->afterCommit();

        return true;
    }
}
