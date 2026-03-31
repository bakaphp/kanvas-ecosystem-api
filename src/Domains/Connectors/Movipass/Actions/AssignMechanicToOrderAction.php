<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AssignMechanicToOrderAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly AppInterface $app,
    ) {
    }

    public function execute(): Users
    {
        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $providerCompany = $this->resolveProviderCompany($assistanceCase);

        $mechanics = new GetAvailableMechanicsAction($this->app, $providerCompany)->execute();

        if ($mechanics->isEmpty()) {
            throw new ValidationException('No available mechanics for this order');
        }

        $mechanic = $this->selectBestMechanic($mechanics, $assistanceCase);

        $mechanicBlock = $this->buildMechanicBlock($mechanic);

        $assistanceCase['mechanic'] = $mechanicBlock;

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();

        $this->order->transitionToStatus(
            auth()->user(),
            MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value,
        );

        return $mechanic;
    }

    protected function resolveProviderCompany(array $assistanceCase): ?Companies
    {
        $providerId = $assistanceCase['provider_id'] ?? null;

        if ($providerId === null) {
            return null;
        }

        return Companies::getById((int) $providerId);
    }

    protected function selectBestMechanic($mechanics, array $assistanceCase): Users
    {
        $orderLocation = is_array($assistanceCase['location'] ?? null) ? $assistanceCase['location'] : null;

        if ($orderLocation === null || ! isset($orderLocation['lat'], $orderLocation['lng'])) {
            return $mechanics->first();
        }

        return $mechanics
            ->sortBy(function (Users $mechanic) use ($orderLocation) {
                $rawLocation = $mechanic->get(CustomFieldEnum::MECHANIC_LOCATION->value);
                $mechanicLocation = is_array($rawLocation) ? $rawLocation : json_decode((string) ($rawLocation ?? ''), true);

                if (! is_array($mechanicLocation) || ! isset($mechanicLocation['lat'], $mechanicLocation['lng'])) {
                    return PHP_INT_MAX;
                }

                return $this->haversineDistance(
                    (float) $orderLocation['lat'],
                    (float) $orderLocation['lng'],
                    (float) $mechanicLocation['lat'],
                    (float) $mechanicLocation['lng'],
                );
            })
            ->first();
    }

    protected function buildMechanicBlock(Users $mechanic): array
    {
        $rawLocation = $mechanic->get(CustomFieldEnum::MECHANIC_LOCATION->value);
        $location = is_array($rawLocation) ? $rawLocation : json_decode((string) ($rawLocation ?? ''), true);

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
            'location' => $location ?: null,
            'vehicle_info' => $vehicleInfo ?: null,
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
