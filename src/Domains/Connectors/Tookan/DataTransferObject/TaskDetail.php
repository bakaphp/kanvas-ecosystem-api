<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\DataTransferObject;

use Spatie\LaravelData\Data;

class TaskDetail extends Data
{
    public function __construct(
        public int $order_id,
        public int $job_description,
        public CustomerDetail $customer,
        public ?string $job_delivery_datetime = null,
        public ?string $job_pickup_datetime = null,
        public ?int $team_id = null,
        public ?int $fleet_id = null,
        public ?string $timezone = null,
        public ?bool $has_pickup = false,
        public ?bool $has_delivery = false,
        public ?string $layout_type = '0', // 0 for delivery, 1 for pickup
        public ?array $meta_data = [],
        public ?string $pickup_delivery_relationship = null,
        public ?array $tags = [],
        public ?bool $auto_assignment = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'order_id' => $this->order_id,
            'job_description' => $this->job_description,
            'customer_email' => $this->customer->email,
            'customer_username' => $this->customer->name,
            'customer_phone' => $this->customer->phone,
            'customer_address' => $this->customer->address,
            'latitude' => $this->customer->latitude,
            'longitude' => $this->customer->longitude,
            'job_delivery_datetime' => $this->job_delivery_datetime,
            'job_pickup_datetime' => $this->job_pickup_datetime,
            'team_id' => $this->team_id,
            'fleet_id' => $this->fleet_id,
            'timezone' => $this->timezone ?? 'UTC',
            'has_pickup' => $this->has_pickup ? 1 : 0,
            'has_delivery' => $this->has_delivery ? 1 : 0,
            'layout_type' => $this->layout_type,
            'meta_data' => $this->meta_data,
            'pickup_delivery_relationship' => $this->pickup_delivery_relationship,
            'tags' => $this->tags,
            'auto_assignment' => $this->auto_assignment ? 1 : 0,
        ];
    }
}
