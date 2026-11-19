<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Support\Geo;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * Live GPS from the APK (spec v6.0 §6.3, §10.7). The first fix after accepting puts the case EN_ROUTE,
 * entering the proximity radius makes it NEARBY, and reaching the arrival threshold runs the shared
 * arrival check that moves the order on_site. The route is kept for the history; old points are
 * dropped from the front so the case document stays bounded.
 */
class RecordRoadsideTrackingAction
{
    use InteractsWithAssistanceCase;

    private const DEFAULT_NEARBY_RADIUS_METERS = 250;
    private const MAX_TRACK_POINTS = 500;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
        private readonly float $lat,
        private readonly float $lng,
    ) {
    }

    /**
     * @return array{distance_meters: ?float, state: ?string}
     */
    public function execute(): array
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if ((int) ($assistanceCase['mechanic']['user_id'] ?? 0) !== $this->technician->getId()) {
            throw new ValidationException('You are not assigned to this order');
        }

        $now = Carbon::now()->toISOString();
        $point = ['lat' => $this->lat, 'lng' => $this->lng, 'at' => $now];

        $this->technician->set(CustomFieldEnum::MECHANIC_LAT->value, $this->lat);
        $this->technician->set(CustomFieldEnum::MECHANIC_LNG->value, $this->lng);

        $assistanceCase['mechanic']['location'] = ['lat' => $this->lat, 'lng' => $this->lng];
        $assistanceCase['tracking'] = [
            'last' => $point,
            'route' => array_slice([...(array) ($assistanceCase['tracking']['route'] ?? []), $point], -self::MAX_TRACK_POINTS),
        ];
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $distance = $this->distanceToUser($assistanceCase);
        $state = RoadsideCaseStateEnum::tryFrom((string) ($assistanceCase['state'] ?? ''));

        if ($state === RoadsideCaseStateEnum::TECHNICIAN_ASSIGNED) {
            $state = $this->moveTo(RoadsideCaseStateEnum::EN_ROUTE) ?? $state;
        }

        $radius = RegionalAssistance::parameter($this->order, ConfigurationEnum::ROADSIDE_NEARBY_RADIUS_METERS, self::DEFAULT_NEARBY_RADIUS_METERS);

        if ($distance !== null && $distance <= $radius && $state === RoadsideCaseStateEnum::EN_ROUTE) {
            $state = $this->moveTo(RoadsideCaseStateEnum::NEARBY) ?? $state;
        }

        if ($distance !== null
            && $distance <= CheckMechanicArrivalAction::ARRIVAL_THRESHOLD_METERS
            && $this->order->orderStatus?->slug === MovipassOrderStatusEnum::DISPATCHED->slug()
            && new CheckMechanicArrivalAction($this->order, $this->technician)->execute()
        ) {
            $state = $this->moveTo(RoadsideCaseStateEnum::ON_SITE) ?? $state;
        }

        return ['distance_meters' => $distance, 'state' => $state?->value];
    }

    private function distanceToUser(array $assistanceCase): ?float
    {
        $location = $assistanceCase['location'] ?? null;

        if (! is_array($location) || ! isset($location['lat'], $location['lng'])) {
            return null;
        }

        $distanceKm = Geo::distanceKm(
            (float) $location['lat'],
            (float) $location['lng'],
            $this->lat,
            $this->lng
        );

        return round($distanceKm * 1000, 1);
    }

    private function moveTo(RoadsideCaseStateEnum $state): ?RoadsideCaseStateEnum
    {
        try {
            new TransitionRoadsideCaseStateAction(
                order: $this->order,
                to: $state,
                actor: $this->technician,
                channel: RoadsideAuditChannelEnum::APK,
            )->execute();
        } catch (ValidationException) {
            return null;
        }

        return $state;
    }
}
