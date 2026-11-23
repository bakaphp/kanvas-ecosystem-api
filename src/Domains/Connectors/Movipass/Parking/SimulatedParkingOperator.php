<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Parking;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Parking\Contracts\ParkingOperator;
use Kanvas\Connectors\Movipass\Parking\DataTransferObject\ParkingQuote;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingOrderVariant;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Models\Order;

class SimulatedParkingOperator implements ParkingOperator
{
    public const string CLOCK_OFFSET_SETTING = 'parking_clock_offset_minutes';
    public const string FORCED_OUTCOME_SETTING = 'parking_forced_outcome';
    public const string DENY_CONFIRMATION = 'deny_confirmation';
    public const string DENY_EXIT = 'deny_exit';
    public const string DENY_ALL = 'deny_all';

    public function quote(Order $order, Carbon $at): ParkingQuote
    {
        $variant = ParkingOrderVariant::from($order);
        $effectiveAt = $at->copy()->addMinutes((int) $variant->product->get(self::CLOCK_OFFSET_SETTING, 0));
        $minutes = max(0, (int) $this->entryAt($order)->diffInMinutes($effectiveAt, false));
        $startedHours = max(1, (int) ceil($minutes / 60));

        return new ParkingQuote(
            amount: round($startedHours * $this->hourlyRate($variant), 2),
            reference: sprintf('SIM-%d-%d', $order->getId(), $effectiveAt->getTimestamp()),
            quotedAt: $effectiveAt,
        );
    }

    public function confirmPayment(Order $order, string $reference): bool
    {
        return ! $this->isForced($order, [self::DENY_CONFIRMATION, self::DENY_ALL]);
    }

    public function authorizeExit(Order $order): bool
    {
        return ! $this->isForced($order, [self::DENY_EXIT, self::DENY_ALL]);
    }

    protected function hourlyRate(Variants $variant): float
    {
        $rate = $variant->variantWarehouses()->first()?->config['rates']['hourly'] ?? null;

        if (! is_numeric($rate)) {
            throw new ValidationException('The parking variant has no hourly rate configured.');
        }

        return (float) $rate;
    }

    protected function entryAt(Order $order): Carbon
    {
        $entryAt = ParkingMetadata::entryAt($order);

        if ($entryAt === null) {
            throw new ValidationException('The parking order has no entry time.');
        }

        return Carbon::parse($entryAt);
    }

    protected function isForced(Order $order, array $outcomes): bool
    {
        $forced = ParkingOrderVariant::from($order)->product->get(self::FORCED_OUTCOME_SETTING);

        return is_string($forced) && in_array($forced, $outcomes, true);
    }
}
