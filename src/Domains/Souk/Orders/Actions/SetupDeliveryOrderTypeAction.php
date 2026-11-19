<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Enums\DeliveryOrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use RuntimeException;

class SetupDeliveryOrderTypeAction
{
    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): array
    {
        $this->renameLegacyPaidStatus();

        $result = new CreateOrderStatusesAction(
            $this->app,
            DeliveryOrderTypeEnum::DELIVERY->value,
            $this->statusGraph()
        )->execute();

        $this->enableStatusHooks($result['order_type']);
        $this->assertConfirmedTransitionExists($result['statuses']);

        return $result;
    }

    private function statusGraph(): array
    {
        $cancelled = DeliveryOrderStatusEnum::CANCELLED->value;
        $outOfStock = DeliveryOrderStatusEnum::OUT_OF_STOCK->value;

        return [
            DeliveryOrderStatusEnum::VERIFYING->value => [
                'name' => 'Order placed, pending confirmation',
                'is_default' => true,
                'transitions' => [DeliveryOrderStatusEnum::CONFIRMED->value, $outOfStock, $cancelled],
            ],
            DeliveryOrderStatusEnum::CONFIRMED->value => [
                'name' => 'Order confirmed',
                'transitions' => [DeliveryOrderStatusEnum::READY_FOR_DISPATCH->value, $outOfStock, $cancelled],
            ],
            DeliveryOrderStatusEnum::READY_FOR_DISPATCH->value => [
                'name' => 'Preparing to ship',
                'transitions' => [DeliveryOrderStatusEnum::DISPATCHED->value, $cancelled],
            ],
            DeliveryOrderStatusEnum::DISPATCHED->value => [
                'name' => 'Shipped',
                'transitions' => [DeliveryOrderStatusEnum::DELIVERED->value, DeliveryOrderStatusEnum::RETURNED->value, $cancelled],
            ],
            DeliveryOrderStatusEnum::DELIVERED->value => ['name' => 'Delivered', 'is_final' => true],
            DeliveryOrderStatusEnum::RETURNED->value => ['name' => 'Returned', 'is_final' => true],
            $outOfStock => ['name' => 'Out of stock', 'is_final' => true],
            $cancelled => ['name' => 'Cancelled', 'is_final' => true],
        ];
    }

    private function renameLegacyPaidStatus(): void
    {
        $orderType = OrderTypes::query()
            ->where('apps_id', $this->app->getId())
            ->where('name', DeliveryOrderTypeEnum::DELIVERY->value)
            ->first();

        if ($orderType === null) {
            return;
        }

        $statuses = OrderStatus::query()->where('order_types_id', $orderType->getId());
        $legacy = (clone $statuses)->where('slug', PaymentStatusEnum::PAID->value)->first();

        if ($legacy === null || $statuses->where('slug', DeliveryOrderStatusEnum::CONFIRMED->value)->exists()) {
            return;
        }

        $legacy->update(['slug' => DeliveryOrderStatusEnum::CONFIRMED->value]);
    }

    private function enableStatusHooks(OrderTypes $orderType): void
    {
        $config = $orderType->config ?? [];

        $hooks = [
            OrderTypes::CAPTURE_ON_STATUS => DeliveryOrderStatusEnum::CONFIRMED,
            OrderTypes::FULFILL_ON_STATUS => DeliveryOrderStatusEnum::DELIVERED,
        ];

        foreach ($hooks as $key => $status) {
            $config[$key] = array_values(array_unique([...($config[$key] ?? []), $status->value]));
        }

        $orderType->update(['config' => $config]);
    }

    private function assertConfirmedTransitionExists(array $statuses): void
    {
        $bySlug = collect($statuses)->keyBy('slug');
        $verifying = $bySlug->get(DeliveryOrderStatusEnum::VERIFYING->value);
        $confirmed = $bySlug->get(DeliveryOrderStatusEnum::CONFIRMED->value);

        $exists = $verifying !== null && $confirmed !== null && OrderStatusTransitions::query()
            ->where('from_status_id', $verifying->getId())
            ->where('to_status_id', $confirmed->getId())
            ->exists();

        if (! $exists) {
            throw new RuntimeException('The verifying to confirmed transition is missing: staff could not confirm the order');
        }
    }
}
