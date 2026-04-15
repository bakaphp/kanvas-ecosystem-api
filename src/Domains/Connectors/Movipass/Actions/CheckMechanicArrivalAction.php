<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CheckMechanicArrivalAction
{
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

        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $assistanceCase['status'] = MovipassOrderStatusEnum::ON_SITE->slug();
        $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
        $assistanceCase['arrived_at'] = Carbon::now()->toISOString();
        $assistanceCase['mechanic']['location'] = $mechanicLocation;

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();

        $this->order->transitionToStatus(
            $this->mechanic,
            MovipassOrderStatusEnum::ON_SITE->slug(),
        );

        return true;
    }

    private function resolveOrderLocation(): ?array
    {
        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);
        $location = $assistanceCase['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        return ['lat' => (float) $location['lat'], 'lng' => (float) $location['lng']];
    }

    private function resolveMechanicLocation(): ?array
    {
        $lat = $this->mechanic->get(CustomFieldEnum::MECHANIC_LAT->value);
        $lng = $this->mechanic->get(CustomFieldEnum::MECHANIC_LNG->value);

        if ($lat === null || $lng === null) {
            return null;
        }

        return ['lat' => (float) $lat, 'lng' => (float) $lng];
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
