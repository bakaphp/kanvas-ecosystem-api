<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\DataTransferObject;

use Spatie\LaravelData\Data;

class TaskMultipleDetail extends Data
{
    public function __construct(
        public array $pickups,
        public array $deliveries,
        public ?int $team_id = null,
        public ?int $fleet_id = null,
        public ?string $timezone = null,
        public ?bool $has_pickup = true,
        public ?bool $has_delivery = true,
        public ?array $meta_data = [],
        public ?bool $auto_assignment = false,
    ) {
    }

    public function toArray(): array
    {
        // Convert PickupDetail objects to arrays
        $pickupsData = array_map(function ($pickup) {
            return $pickup instanceof PickupDetail ? $pickup->toArray() : $pickup;
        }, $this->pickups);

        // Convert DeliveryDetail objects to arrays
        $deliveriesData = array_map(function ($delivery) {
            return $delivery instanceof DeliveryDetail ? $delivery->toArray() : $delivery;
        }, $this->deliveries);

        return array_filter([
            'pickups' => $pickupsData,
            'deliveries' => $deliveriesData,
            'team_id' => $this->team_id,
            'fleet_id' => $this->fleet_id,
            'timezone' => $this->timezone ?? '330',
            'has_pickup' => $this->has_pickup ? 1 : 0,
            'has_delivery' => $this->has_delivery ? 1 : 0,
            'meta_data' => $this->meta_data,
            'auto_assignment' => $this->auto_assignment ? 1 : 0,
            "layout_type" => 0,
            "geofence" => 0,
            "tags" => "",
        ], fn($value) => $value !== null && $value !== []);
    }
}
