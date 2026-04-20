<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Events\AssistanceAssignedEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AssignMechanicToOrderAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly UserInterface $user,
        protected readonly ?Users $mechanic = null,
    ) {
    }

    public function execute(): Users
    {
        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        if ($this->mechanic !== null) {
            $mechanic = $this->mechanic;
        } else {
            $mechanics = new GetAvailableMechanicsAction()->execute();

            if ($mechanics->isEmpty()) {
                throw new ValidationException('No available mechanics for this order');
            }

            $mechanic = $this->selectBestMechanic($mechanics, $assistanceCase);
        }

        $existingMechanicData = is_array($assistanceCase['mechanic'] ?? null) ? $assistanceCase['mechanic'] : [];
        $mechanicBlock = $this->buildMechanicBlock($mechanic, $existingMechanicData);

        $assistanceCase['mechanic'] = $mechanicBlock;
        $assistanceCase['status'] = MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug();
        $assistanceCase['status_updated_at'] = Carbon::now()->toISOString();
        unset($assistanceCase['assign_mechanic_id']);

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();
        $this->order->set(CustomFieldEnum::ORDER_MECHANIC_USERS_ID->value, $mechanic->getId());

        $this->order->transitionToStatus(
            $this->user,
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug(),
        );

        $this->order->refresh();
        new GenerateRoadsideAssistancePinAction($this->order)->execute();

        $this->order->refresh();
        $this->order->transitionToStatus(
            $this->user,
            MovipassOrderStatusEnum::DISPATCHED->slug(),
        );

        AssistanceAssignedEvent::dispatch($this->order, $mechanic);

        $mechanic->notify(new RoadsideAssistanceStatusNotification(
            $this->order,
            'Order assigned',
            'You have been assigned to a roadside assistance order.',
            MovipassOrderStatusEnum::DISPATCHED->slug(),
        ));

        $this->order->user->notify(new RoadsideAssistanceStatusNotification(
            $this->order,
            'Mechanic assigned',
            'A mechanic has been assigned to your order and is on the way.',
            MovipassOrderStatusEnum::DISPATCHED->slug(),
        ));

        return $mechanic;
    }

    protected function selectBestMechanic($mechanics, array $assistanceCase): Users
    {
        $orderLocation = is_array($assistanceCase['location'] ?? null) ? $assistanceCase['location'] : null;

        if ($orderLocation === null || ! isset($orderLocation['lat'], $orderLocation['lng'])) {
            return $mechanics->first();
        }

        return $mechanics
            ->sortBy(function (Users $mechanic) use ($orderLocation) {
                $lat = $mechanic->get(CustomFieldEnum::MECHANIC_LAT->value);
                $lng = $mechanic->get(CustomFieldEnum::MECHANIC_LNG->value);

                if ($lat === null || $lng === null) {
                    return PHP_INT_MAX;
                }

                return $this->haversineDistance(
                    (float) $orderLocation['lat'],
                    (float) $orderLocation['lng'],
                    (float) $lat,
                    (float) $lng,
                );
            })
            ->first();
    }

    protected function buildMechanicBlock(Users $mechanic, array $existingData = []): array
    {
        $lat = $mechanic->get(CustomFieldEnum::MECHANIC_LAT->value);
        $lng = $mechanic->get(CustomFieldEnum::MECHANIC_LNG->value);
        $profileLocation = $lat !== null && $lng !== null ? ['lat' => (float) $lat, 'lng' => (float) $lng] : null;

        $rawVehicleInfo = $mechanic->get(CustomFieldEnum::MECHANIC_VEHICLE_INFO->value);
        $vehicleInfo = is_array($rawVehicleInfo) ? $rawVehicleInfo : json_decode((string) ($rawVehicleInfo ?? ''), true);

        return [
            'user_id' => $mechanic->getId(),
            'uuid' => $mechanic->uuid,
            'name' => trim($mechanic->firstname . ' ' . $mechanic->lastname),
            'phone' => $mechanic->phone_number ?? null,
            'email' => $mechanic->email,
            'company_id' => $mechanic->default_company,
            'company_name' => $mechanic->getCurrentCompany()?->name ?? null,
            'location' => (is_array($existingData['location'] ?? null) ? $existingData['location'] : null) ?? $profileLocation,
            'vehicle_info' => (is_array($existingData['vehicle_info'] ?? null) ? $existingData['vehicle_info'] : null) ?? ($vehicleInfo ?: null),
        ];
    }

    protected function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(sqrt($a));
    }
}
