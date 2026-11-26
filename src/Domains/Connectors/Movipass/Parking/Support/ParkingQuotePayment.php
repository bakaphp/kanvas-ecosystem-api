<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Support;

use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

final class ParkingQuotePayment
{
    private const array OPEN_STATUSES = [
        PaymentStatusEnum::PENDING,
        PaymentStatusEnum::PROCESSING,
        PaymentStatusEnum::WAITING_DEVICE_DATA,
        PaymentStatusEnum::PENDING_AUTHORIZATION,
        PaymentStatusEnum::AUTHORIZED,
    ];

    public static function amountDueInCents(Order $order): ?int
    {
        $amount = ParkingMetadata::of($order)[ParkingMetadata::QUOTE]['amount_due'] ?? null;

        return is_numeric($amount) ? self::toCents($amount) : null;
    }

    public static function isPaid(Order $order): bool
    {
        $due = self::amountDueInCents($order);

        if ($due === null) {
            return false;
        }

        return $order->payments()
            ->where('status', PaymentStatusEnum::PAID->value)
            ->pluck('amount')
            ->contains(fn ($amount): bool => self::toCents($amount) === $due);
    }

    public static function hasPaidPayment(Order $order): bool
    {
        return $order->payments()->where('status', PaymentStatusEnum::PAID->value)->exists();
    }

    public static function hasOpenPayment(Order $order): bool
    {
        return $order->payments()
            ->whereIn('status', array_map(fn (PaymentStatusEnum $status): string => $status->value, self::OPEN_STATUSES))
            ->exists();
    }

    private static function toCents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
