<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Support;

use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Parking\Enums\ParkingStateEnum;
use Kanvas\Souk\Orders\Models\Order;

final class ParkingStateResolver
{
    public static function for(Order $order): ?ParkingStateEnum
    {
        if (! ParkingMetadata::hasEntered($order)) {
            return null;
        }

        $parking = ParkingMetadata::of($order);

        return match (true) {
            $order->orderStatus?->slug === MovipassOrderStatusEnum::COMPLETED->slug() => ParkingStateEnum::FINISHED,
            $order->orderStatus?->slug === MovipassOrderStatusEnum::CANCELLED->slug() => ParkingStateEnum::CANCELLED,
            ParkingMetadata::isPaymentConfirmed($order) => ParkingStateEnum::EXIT_ENABLED,
            ! ParkingQuotePayment::isPaid($order) => ParkingStateEnum::IN_PROGRESS,
            empty($parking[ParkingMetadata::CONFIRMATION_ATTEMPTED_AT]) => ParkingStateEnum::PAYMENT_APPROVED,
            default => ParkingStateEnum::PENDING_CONFIRMATION,
        };
    }
}
