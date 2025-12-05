<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Actions;

use Kanvas\Connectors\Tookan\DataTransferObject\CustomerDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskDetail;
use Kanvas\Connectors\Tookan\Services\TookanService;
use Kanvas\Souk\Orders\Models\Order;

class CreateTookanTaskAction
{
    public function __construct(
        private Order $order
    ) {
    }

    public function execute(): array
    {
        $latitude = $this->order->shippingAddress->latitude ?? $this->order->metadata["data"]['latitude'];
        $longitude = $this->order->shippingAddress->longitude ?? $this->order->metadata["data"]['longitude'];

        $customerDetail = new CustomerDetail(
            name: $this->order->people->name,
            phone: $this->order->people->getPhones()->first()?->value,
            email: $this->order->people->getEmails()->first()?->value,
            address: '123 Main Street, New York, NY 10001',
            latitude: $latitude,
            longitude: $longitude,
        );

        $deliveryTime = now()->addHours(2);

        $taskDetail = new TaskDetail(
            order_id: $this->order->id,
            job_description: 'Deliver package to customer',
            customer: $customerDetail,
            job_delivery_datetime: $deliveryTime->format('m/d/Y H:i'),
            job_pickup_datetime: $deliveryTime->copy()->subMinutes(30)->format('m/d/Y H:i'),
            team_id: 0,
            timezone: '330',
            has_delivery: true,
            has_pickup: true,
            layout_type: '0',
            auto_assignment: false
        );

        $tookanService = new TookanService($this->order->app, $this->order->company);

        return $tookanService->createTask($taskDetail);
    }
}
