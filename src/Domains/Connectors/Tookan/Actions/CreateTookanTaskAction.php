<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Actions;

use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Tookan\DataTransferObject\CustomerDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\DeliveryDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\PickupDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskMultipleDetail;
use Kanvas\Connectors\Tookan\Services\TookanService;
use Kanvas\Connectors\VinSolution\Dealers\User;
use Kanvas\Souk\Orders\Models\Order;

class CreateTookanTaskAction
{
    public function __construct(
        private Order $order,
        private Companies $company,
        Private ?Companies $receiverCompany = null,
        Private ?User $receiverUser = null,
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
            address: $this->order->shippingAddress->address_line_1 . ' ' . $this->order->shippingAddress->address_line_2,
            latitude: $latitude,
            longitude: $longitude,
        );

        $deliveryTime = now()->addHours(2);

        // Create two pickup locations (restaurants)
        $companyAddress = $this->company->defaultAddress;

        $pickups = [
            new PickupDetail(
                address: $companyAddress?->address_line_1 . ' ' . $companyAddress?->address_line_2,
                latitude: $companyAddress?->latitude,
                longitude: $companyAddress?->longitude,
                time: now()->addHour()->format('Y-m-d H:i:s'),
                phone: $this->company->phone_number,
                job_description: 'Pick up order from ' . $this->company->name,
                name: $this->company->name,
                order_id: (string) $this->order->id,
                email: $this->company->email
            )
        ];

        $destinationAddress = $this->receiverCompany?->defaultAddress ?? $this->receiverUser?->defaultAddress;
        $phoneNumber = $this->receiverCompany?->phone_number ?? $this->receiverUser?->phone_number;
        $destinationName = $this->receiverCompany?->name ?? $this->receiverUser?->firstname . ' ' . $this->receiverUser?->lastname;
        // Create delivery location (customer)
        $deliveries = [
            new DeliveryDetail(
                address: $destinationAddress?->address_line_1 . ' ' . $destinationAddress?->address_line_2,
                latitude: $destinationAddress?->latitude,
                longitude: $destinationAddress?->longitude,
                time: now()->addHours(2)->format('Y-m-d H:i:s'),
                phone: $phoneNumber,
                job_description: 'Deliver order to ' . $destinationName,
                name: $destinationName,
                order_id: (string) $this->order->id,
                email: $this->receiverCompany?->email ?? $this->receiverUser?->email
            ),
        ];

          $task = new TaskMultipleDetail(
            pickups: $pickups,
            deliveries: $deliveries,
            team_id: 0,
            timezone: '330',
            has_pickup: true,
            has_delivery: true,
            auto_assignment: false
        );

        $tookanService = new TookanService($this->order->app, $this->order->company);
        try {
            $taskResponse = $tookanService->createMultipleTasks($task);
            $this->order->set("tookan_task_id", $taskResponse['data'][0]['job_id']);
            activity()
                ->causedBy($this->order->user)
                ->performedOn($this->order)
                ->withProperties([
                    'tookan_response' => $taskResponse,
                ])
                ->log('Tookan Create Task Success');
            return $taskResponse;
        } catch (Exception $e) {
            report($e);
            activity()
                ->causedBy($this->order->user)
                ->performedOn($this->order)
                ->withProperties([
                    'error' => $e->getMessage(),
                ])
                ->log('Tookan Create Task Error');
            throw new Exception('Failed to create Tookan Task: ' . $e->getMessage());
        }
    }
}
