<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AcceptOrderAssignmentAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly Users $mechanic,
    ) {
    }

    public function execute(): Order
    {
        return DB::connection('commerce')->transaction(function () {
            $order = Order::where('id', $this->order->getId())
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->orderStatus?->slug !== MovipassOrderStatusEnum::AWAITING_OPERATOR->slug()) {
                throw new ValidationException('Order is no longer available for assignment');
            }

            $metadata = $order->metadata ?? [];
            $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);
            $notifiedIds = $assistanceCase['notified_mechanic_ids'] ?? [];

            if (! in_array($this->mechanic->getId(), $notifiedIds, true)) {
                throw new ValidationException('Mechanic was not notified of this order');
            }

            $existingMechanicData = is_array($assistanceCase['mechanic'] ?? null) ? $assistanceCase['mechanic'] : [];
            $mechanicBlock = $this->buildMechanicBlock($existingMechanicData);
            $assistanceCase['mechanic'] = $mechanicBlock;

            $order->metadata = [
                ...$metadata,
                'assistance_case' => $assistanceCase,
                'data' => [
                    ...($metadata['data'] ?? []),
                    'assistance_case' => $assistanceCase,
                ],
            ];
            $order->saveQuietly();

            $order->transitionToStatus(
                $this->mechanic,
                MovipassOrderStatusEnum::PROVIDER_ASSIGNED->slug(),
            );

            return $order;
        });
    }

    protected function buildMechanicBlock(array $existingData = []): array
    {
        $mechanic = $this->mechanic;

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
}
