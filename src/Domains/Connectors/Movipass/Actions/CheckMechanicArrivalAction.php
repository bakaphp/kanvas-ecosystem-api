<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CheckMechanicArrivalAction
{
    use InteractsWithAssistanceCase;

    // Threshold in meters to consider mechanic on-site
    private const ARRIVAL_THRESHOLD_METERS = 100;

    public function __construct(
        protected readonly Order $order,
        protected readonly Users $mechanic,
    ) {
    }

    /**
     * Check if mechanic is within arrival threshold of the order location.
     * If so, transition the order to on_site and return true.
     */
    public function execute(): bool
    {
        $orderLocation = $this->resolveOrderLocation();
        $mechanicLocation = $this->resolveMechanicLocation();

        if ($orderLocation === null || $mechanicLocation === null) {
            return false;
        }

        $distanceMeters = $this->haversineDistance(
            $orderLocation['lat'],
            $orderLocation['lng'],
            $mechanicLocation['lat'],
            $mechanicLocation['lng'],
        ) * 1000;

        if ($distanceMeters > self::ARRIVAL_THRESHOLD_METERS) {
            return false;
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);

        $assistanceCase['status'] = MovipassOrderStatusEnum::ON_SITE->slug();
        $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
        $assistanceCase['arrived_at'] = Carbon::now()->toISOString();
        $assistanceCase['mechanic']['location'] = $mechanicLocation;

        $this->saveAssistanceCase(
            $this->order,
            $this->markStatusNotified($assistanceCase, MovipassOrderStatusEnum::ON_SITE->slug()),
        );

        $this->order->transitionToStatus(
            $this->mechanic,
            MovipassOrderStatusEnum::ON_SITE->slug(),
        );

        RefreshActiveAssistanceEvent::dispatch($this->order, $this->mechanic->getId());

        $this->notifySafely(
            $this->resolveCaseUser($this->order->users_id),
            new RoadsideAssistanceStatusNotification(
                $this->order,
                'Mechanic on site',
                'The mechanic has arrived at your location.',
                MovipassOrderStatusEnum::ON_SITE->slug(),
            ),
        );

        return true;
    }

    private function resolveOrderLocation(): ?array
    {
        $location = $this->assistanceCaseFrom($this->order)['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }

    private function resolveMechanicLocation(): ?array
    {
        // Primary: mechanic's real-time custom field GPS
        $lat = $this->mechanic->get(CustomFieldEnum::MECHANIC_LAT->value);
        $lng = $this->mechanic->get(CustomFieldEnum::MECHANIC_LNG->value);

        if ($lat !== null && $lng !== null) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        // Fallback: location stored in the order's mechanic block (sent by SDK on update)
        $mechanicLocation = $this->assistanceCaseFrom($this->order)['mechanic']['location'] ?? null;

        if (is_array($mechanicLocation) && isset($mechanicLocation['lat'], $mechanicLocation['lng'])) {
            return ['lat' => (float) $mechanicLocation['lat'], 'lng' => (float) $mechanicLocation['lng']];
        }

        return null;
    }

    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(sqrt($a));
    }
}
