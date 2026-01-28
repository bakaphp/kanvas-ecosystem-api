<?php

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Str;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;

class CreateOrderStatusesAction
{
    public function __construct(
        protected AppInterface $app,
        protected string $orderTypeName,
        protected array $statuses
    ) {
    }

    public function execute(): array
    {
        // Find or create order types
        $orderType = OrderTypes::firstOrCreate([
            'apps_id' => $this->app->getId(),
            'name' => $this->orderTypeName,
        ], [
            'slug' => Str::slug($this->orderTypeName),
            'is_default' => false,
        ]);

        $createdStatuses = [];
        $statusModels = [];

        // Create statuses
        foreach ($this->statuses as $statusName => $config) {
            $isDefault = $config['is_default'] ?? false;
            $isFinal = $config['is_final'] ?? false;

            $status = OrderStatus::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'order_types_id' => $orderType->getId(),
                'slug' => Str::slug($statusName),
            ], [
                'name' => ucfirst(str_replace('-', ' ', $statusName)),
                'is_default' => $isDefault,
                'is_final' => $isFinal,
            ]);

            $statusModels[$statusName] = $status;
            $createdStatuses[] = $status;
        }

        // Create transitions
        foreach ($this->statuses as $statusName => $config) {
            if (isset($config['transitions']) && ! empty($config['transitions'])) {
                $fromStatus = $statusModels[$statusName];

                foreach ($config['transitions'] as $toStatusName) {
                    if (isset($statusModels[$toStatusName])) {
                        $toStatus = $statusModels[$toStatusName];

                        OrderStatusTransitions::firstOrCreate([
                            'order_types_id' => $orderType->getId(),
                            'from_status_id' => $fromStatus->getId(),
                            'to_status_id' => $toStatus->getId(),
                        ], [
                            'name' => "{$fromStatus->name} to {$toStatus->name}",
                        ]);
                    }
                }
            }
        }

        return [
            'order_type' => $orderType,
            'statuses' => $createdStatuses,
        ];
    }
}
