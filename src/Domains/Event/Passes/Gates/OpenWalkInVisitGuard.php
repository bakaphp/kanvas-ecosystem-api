<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Gates;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\DataTransferObject\WalkInAccess;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;

final class OpenWalkInVisitGuard
{
    private const array CLOSED_ORDER_STATUS_SLUGS = ['completed', 'cancelled'];
    private const int LOCK_SECONDS = 30;
    private const int WAIT_SECONDS = 10;

    public static function run(Apps $app, array $bookingMetadata, Closure $callback): mixed
    {
        $vehicleVariantId = self::vehicleVariantIdOf($bookingMetadata);

        try {
            return Cache::lock(
                'event:walk-in-vehicle:' . $app->getId() . ':' . $vehicleVariantId,
                self::LOCK_SECONDS
            )->block(
                self::WAIT_SECONDS,
                function () use ($app, $vehicleVariantId, $callback) {
                    self::assertNoOpenVisit($app, $vehicleVariantId);

                    return $callback();
                }
            );
        } catch (LockTimeoutException) {
            throw new ValidationException('This vehicle is being booked right now. Please try again.');
        }
    }

    private static function vehicleVariantIdOf(array $bookingMetadata): string
    {
        $vehicleVariantId = $bookingMetadata['variants_ids']['vehicle_variant_id'] ?? null;

        if ($vehicleVariantId === null || $vehicleVariantId === '') {
            throw new ValidationException('A vehicle is required to book a walk-in visit.');
        }

        return (string) $vehicleVariantId;
    }

    private static function assertNoOpenVisit(Apps $app, string $vehicleVariantId): void
    {
        $eventIds = EventVersion::query()
            ->fromApp($app)
            ->live()
            ->where('metadata->variants_ids->vehicle_variant_id', $vehicleVariantId)
            ->pluck('event_id');

        if ($eventIds->isEmpty() || ! self::hasOpenWalkInVisit($eventIds->all())) {
            return;
        }

        throw new ValidationException('This vehicle already has an open visit.');
    }

    private static function hasOpenWalkInVisit(array $eventIds): bool
    {
        $openEventIds = Order::query()
            ->where('resources_type', new Event()->getMorphClass())
            ->whereIn('resources_id', $eventIds)
            ->whereNotIn('status', OrderStatusEnum::closedValues())
            ->whereDoesntHave('orderStatus', fn (Builder $status) => $status->whereIn('slug', self::CLOSED_ORDER_STATUS_SLUGS))
            ->pluck('resources_id');

        if ($openEventIds->isEmpty()) {
            return false;
        }

        return Event::query()
            ->whereIn('id', $openEventIds)
            ->get()
            ->unique(fn (Event $event): string => $event->resources_type . ':' . $event->resources_id)
            ->contains(fn (Event $event): bool => WalkInAccess::forResource($event->resource) !== null);
    }
}
