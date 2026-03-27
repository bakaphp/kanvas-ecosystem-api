<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Actions;

use DateTime;
use DateTimeZone;
use Exception;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Tookan\DataTransferObject\CustomerDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\DeliveryDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\PickupDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskDetail;
use Kanvas\Connectors\Tookan\DataTransferObject\TaskMultipleDetail;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Services\TookanService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CreateTookanTaskAction
{
    public function __construct(
        private Order $order,
        private Companies $company,
        private ?Companies $receiverCompany = null,
        private ?Users $receiverUser = null,
    ) {
    }

    public function execute($isMulti = false): array
    {
        if ($isMulti) {
            return $this->createMulti();
        }
        return $this->createSingle();
    }

    public function createSingle(): array
    {
        $companyAddress = $this->company->defaultAddress;
        $destinationAddress = $this->receiverCompany?->defaultAddress ?? $this->receiverUser?->defaultAddress;
        $phoneNumber = $this->receiverCompany?->phone ?? $this->receiverUser?->phone_number;
        $destinationName = $this->receiverCompany?->name ?? $this->receiverUser?->firstname . ' ' . $this->receiverUser?->lastname;

        $orderMetadata = $this->order->metadata['data'] ?? [];
        $destinationLatitude = $this->receiverUser
            ? ($orderMetadata['latitude'] ?? $destinationAddress?->latitude)
            : $destinationAddress?->latitude;
        $destinationLongitude = $this->receiverUser
            ? ($orderMetadata['longitude'] ?? $destinationAddress?->longitude)
            : $destinationAddress?->longitude;

        $customer = new CustomerDetail(
            name: $destinationName,
            email: $this->receiverCompany?->email ?? $this->receiverUser?->email,
            phone: $phoneNumber,
            address: $destinationAddress?->address . ' ' . $destinationAddress?->address_2,
            latitude: $destinationLatitude,
            longitude: $destinationLongitude,
        );
        $tzName = $this->order->app->get(ConfigurationEnum::TIMEZONE->value) ?? 'UTC';
        $tzOffset = (int) ((new DateTimeZone($tzName))->getOffset(new DateTime()) / 60);
        $estimatedDelivery = now()->timezone($tzName)->addHours(1)->format('Y-m-d H:i:s');

        $task = new TaskDetail(
            job_description: 'Pickup order from ' . $this->company->name,
            team_id: 0,
            timezone: (string) $tzOffset,
            customer: $customer,
            order_id: $this->order->id,
            job_pickup_address: $companyAddress?->address . ' ' . $companyAddress?->address_2,
            job_pickup_latitude: $companyAddress?->latitude,
            job_pickup_longitude: $companyAddress?->longitude,
            job_pickup_datetime: now()->timezone($tzName)->addMinutes(30)->format('Y-m-d H:i:s'),
            job_pickup_phone: $this->company->phone,
            job_pickup_name: $this->company->name,
            job_pickup_email: $this->company->email,
            has_pickup: true,
            has_delivery: true,
            auto_assignment: false,
            job_delivery_datetime: $estimatedDelivery,
        );

        $tookanService = new TookanService($this->order->app, $this->order->company);
        try {
            $taskResponse = $tookanService->createTask($task);
            $this->order->set("tookan_task_id", $taskResponse['job_id']);
            $this->order->set("tookan_tracking_link", $taskResponse['tracking_link']);
            $this->order->updateQuietly(['estimate_shipping_date' => $estimatedDelivery]);
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
            throw new Exception('Failed to create Tookan Task: ' . $e->getMessage());
        }
    }

    public function createMulti(): array
    {
        $tzName = $this->order->app->get(ConfigurationEnum::TIMEZONE->value) ?? 'UTC';
        $tzOffset = (int) ((new DateTimeZone($tzName))->getOffset(new DateTime()) / 60);

        $companyAddress = $this->company->defaultAddress;

        $pickups = [
            new PickupDetail(
                address: $companyAddress?->address . ' ' . $companyAddress?->address_2,
                latitude: (float) $companyAddress?->latitude,
                longitude: (float) $companyAddress?->longitude,
                time: now()->timezone($tzName)->addHour()->format('Y-m-d H:i:s'),
                phone: $this->company->phone,
                job_description: 'Pick up order from ' . $this->company->name,
                name: $this->company->name,
                order_id: (string) $this->order->id,
                email: $this->company->email
            )
        ];

        $destinationAddress = $this->receiverCompany?->defaultAddress ?? $this->receiverUser?->defaultAddress;
        $phoneNumber = $this->receiverCompany?->phone ?? $this->receiverUser?->phone_number;
        $destinationName = $this->receiverCompany?->name ?? $this->receiverUser?->firstname . ' ' . $this->receiverUser?->lastname;
        // Create delivery location (customer)
        $deliveries = [
            new DeliveryDetail(
                address: $destinationAddress?->address . ' ' . $destinationAddress?->address_2,
                latitude: $destinationAddress?->latitude,
                longitude: $destinationAddress?->longitude,
                time: now()->timezone($tzName)->addHours(2)->format('Y-m-d H:i:s'),
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
            timezone: (string) $tzOffset,
            has_pickup: true,
            has_delivery: true,
            auto_assignment: false
        );

        $tookanService = new TookanService($this->order->app, $this->order->company);
        try {
            $taskResponse = $tookanService->createMultipleTasks($task);
            $this->order->set("tookan_task_id", $taskResponse['job_id']);
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
            throw new Exception('Failed to create Tookan Task: ' . $e->getMessage());
        }
    }
}
