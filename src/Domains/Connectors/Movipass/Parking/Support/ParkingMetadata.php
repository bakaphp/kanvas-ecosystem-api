<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking\Support;

use Kanvas\Souk\Orders\Models\Order;

final class ParkingMetadata
{
    public const string ENTRY_AT = 'entry_at';
    public const string EXIT_AT = 'exit_at';
    public const string QUOTE = 'quote';
    public const string CONFIRMATION_ATTEMPTED_AT = 'payment_confirmation_attempted_at';
    public const string PAYMENT_CONFIRMED_AT = 'payment_confirmed_at';

    public static function of(Order $order): array
    {
        $parking = $order->metadata['data']['parking'] ?? [];

        return is_array($parking) ? $parking : [];
    }

    public static function hasEntered(Order $order): bool
    {
        return self::entryAt($order) !== null;
    }

    public static function entryAt(Order $order): ?string
    {
        $entryAt = self::of($order)[self::ENTRY_AT] ?? null;

        return is_string($entryAt) && $entryAt !== '' ? $entryAt : null;
    }

    public static function isPaymentConfirmed(Order $order): bool
    {
        return ! empty(self::of($order)[self::PAYMENT_CONFIRMED_AT]);
    }

    public static function put(Order $order, array $changes): void
    {
        $order->refresh();

        $order->metadata = [
            ...$order->metadata ?? [],
            'data' => [
                ...$order->metadata['data'] ?? [],
                'parking' => [...self::of($order), ...$changes],
            ],
        ];
        $order->saveQuietly();
    }
}
