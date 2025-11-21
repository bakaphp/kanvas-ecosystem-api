<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\DataTransferObject;

use Spatie\LaravelData\Data;

class DeliveryDetail extends Data
{
    public function __construct(
        public string $address,
        public float $latitude,
        public float $longitude,
        public string $time,
        public string $phone,
        public string $job_description,
        public string $name,
        public string $order_id,
        public ?string $email = null,
        public ?string $template_name = null,
        public ?array $template_data = null,
        public ?array $ref_images = null,
    ) {
    }

    public function toArray(): array
    {
        return array_filter([
            'address' => $this->address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'time' => $this->time,
            'phone' => $this->phone,
            'job_description' => $this->job_description,
            'name' => $this->name,
            'order_id' => $this->order_id,
            'email' => $this->email,
            'template_name' => $this->template_name,
            'template_data' => $this->template_data,
            'ref_images' => $this->ref_images,
        ], fn($value) => $value !== null);
    }
}
