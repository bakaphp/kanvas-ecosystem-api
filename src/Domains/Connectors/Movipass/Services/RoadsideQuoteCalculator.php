<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Support\Geo;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsidePriceBreakdown;
use Kanvas\Connectors\Movipass\Support\RoadsideQuote;
use Kanvas\Connectors\Movipass\Support\RoadsideTariffSchedule;
use Kanvas\Exceptions\ValidationException;

class RoadsideQuoteCalculator
{
    public function __construct(
        private readonly RoadsideTariffSchedule $schedule,
    ) {
    }

    /**
     * The zone comes from where the user is; the billable distance from where the unit ends up.
     * Both are measured server-side because the client controls everything else on the order.
     */
    public function quote(
        RoadsideServiceTypeEnum $service,
        array $location,
        ?array $destination,
        Carbon $moment,
        float $additionalCharges = 0.0
    ): RoadsideQuote {
        $origin = $this->point($location, 'location');
        $billedPoint = $service->billsDistanceToDestination()
            ? $this->point($destination, 'destination')
            : $origin;

        $zone = $this->schedule->zoneFor($origin['lat'], $origin['lng']);
        $kmZero = $this->schedule->kmZero();
        $distanceKm = Geo::distanceKm(
            $kmZero['lat'],
            $kmZero['lng'],
            $billedPoint['lat'],
            $billedPoint['lng']
        );

        $perKmRate = $this->schedule->perKmRate($service);
        // Kilometres are charged per started km, matching how the provider bills the tow.
        $billableKm = (int) ceil(max(0.0, $distanceKm - $this->schedule->includedKm()));
        $extraKmAmount = $perKmRate === null ? 0.0 : round($billableKm * $perKmRate, 2);

        $baseFare = $this->schedule->baseFare($service, $zone);
        $surchargeReasons = $this->schedule->surchargeReasonsAt($moment);

        return new RoadsideQuote(
            serviceType: $service,
            zone: $zone,
            baseFare: $baseFare,
            distanceKm: $distanceKm,
            includedKm: $this->schedule->includedKm(),
            billableKm: $billableKm,
            perKmRate: $perKmRate,
            extraKmAmount: $extraKmAmount,
            additionalCharges: round($additionalCharges, 2),
            surchargeReasons: $surchargeReasons,
            price: RoadsidePriceBreakdown::compute(
                $baseFare + $extraKmAmount + $additionalCharges,
                $surchargeReasons === [] ? 0.0 : $this->schedule->surchargeRate(),
                $this->schedule->taxRate(),
            ),
            quotedAt: $moment->toIso8601String(),
        );
    }

    /**
     * The surcharge follows the order, not the moment the charge is billed: one order, one set of
     * conditions. Read from the schedule rather than the quote copy in metadata, which the client
     * can write.
     */
    public function priceAdditionalCharge(float $amount, Carbon $orderCreatedAt): RoadsidePriceBreakdown
    {
        return RoadsidePriceBreakdown::compute(
            $amount,
            $this->schedule->surchargeReasonsAt($orderCreatedAt) === [] ? 0.0 : $this->schedule->surchargeRate(),
            $this->schedule->taxRate(),
        );
    }

    /**
     * @return array{lat: float, lng: float}
     */
    private function point(?array $point, string $name): array
    {
        if (! isset($point['lat'], $point['lng']) || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])) {
            throw new ValidationException(sprintf('Roadside assistance %s with lat and lng is required', $name));
        }

        return ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
    }
}
