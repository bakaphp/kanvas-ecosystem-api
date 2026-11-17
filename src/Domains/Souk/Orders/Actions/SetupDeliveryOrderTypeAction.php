<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Souk\Orders\Enums\DeliveryOrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use RuntimeException;

class SetupDeliveryOrderTypeAction
{
    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): array
    {
        $result = new CreateOrderStatusesAction(
            $this->app,
            DeliveryOrderTypeEnum::DELIVERY->value,
            $this->statusGraph()
        )->execute();

        $this->assertPaidTransitionExists($result['statuses']);

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
                'transitions' => [DeliveryOrderStatusEnum::PAID->value, $outOfStock, $cancelled],
            ],
            DeliveryOrderStatusEnum::PAID->value => [
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

    private function assertPaidTransitionExists(array $statuses): void
    {
        $bySlug = collect($statuses)->keyBy('slug');
        $verifying = $bySlug->get(DeliveryOrderStatusEnum::VERIFYING->value);
        $paid = $bySlug->get(PaymentStatusEnum::PAID->value);

        $exists = $verifying !== null && $paid !== null && OrderStatusTransitions::query()
            ->where('from_status_id', $verifying->getId())
            ->where('to_status_id', $paid->getId())
            ->exists();

        if (! $exists) {
            throw new RuntimeException('The verifying to paid transition is missing: capturing a payment would leave the order in verifying');
        }
    }
}
