<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideSurchargeReasonEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideZoneEnum;

final class RoadsideQuote
{
    /**
     * @param array<int, RoadsideSurchargeReasonEnum> $surchargeReasons
     */
    public function __construct(
        public readonly RoadsideServiceTypeEnum $serviceType,
        public readonly RoadsideZoneEnum $zone,
        public readonly float $baseFare,
        public readonly float $distanceKm,
        public readonly float $includedKm,
        public readonly int $billableKm,
        public readonly ?float $perKmRate,
        public readonly float $extraKmAmount,
        public readonly float $additionalCharges,
        public readonly array $surchargeReasons,
        public readonly RoadsidePriceBreakdown $price,
        public readonly string $quotedAt,
    ) {
    }

    public function toArray(): array
    {
        return [
            'service_type' => $this->serviceType->value,
            'zone' => $this->zone->value,
            'base_fare' => $this->baseFare,
            'distance_km' => round($this->distanceKm, 2),
            'included_km' => $this->includedKm,
            'billable_km' => $this->billableKm,
            'per_km_rate' => $this->perKmRate,
            'extra_km_amount' => $this->extraKmAmount,
            'additional_charges' => $this->additionalCharges,
            'surcharge_reasons' => array_map(
                static fn (RoadsideSurchargeReasonEnum $reason): string => $reason->value,
                $this->surchargeReasons,
            ),
            ...$this->price->toArray(),
            'quoted_at' => $this->quotedAt,
        ];
    }
}
