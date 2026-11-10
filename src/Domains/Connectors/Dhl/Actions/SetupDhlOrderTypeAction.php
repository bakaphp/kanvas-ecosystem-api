<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Connectors\Dhl\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Actions\CreateOrderStatusesAction;
use Kanvas\Souk\Orders\Models\OrderStatusTransitions;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use RuntimeException;

class SetupDhlOrderTypeAction
{
    public function __construct(protected AppInterface $app)
    {
    }

    public function execute(): array
    {
        $result = new CreateOrderStatusesAction(
            $this->app,
            OrderTypeEnum::DELIVERY->value,
            $this->statusGraph()
        )->execute();

        $this->assertPaidTransitionExists($result['statuses']);

        return $result;
    }

    private function statusGraph(): array
    {
        $cancelled = OrderStatusEnum::CANCELLED->value;
        $outOfStock = OrderStatusEnum::OUT_OF_STOCK->value;

        return [
            OrderStatusEnum::VERIFYING->value => [
                'name' => 'Order placed, pending confirmation',
                'is_default' => true,
                'transitions' => [OrderStatusEnum::PAID->value, $outOfStock, $cancelled],
            ],
            OrderStatusEnum::PAID->value => [
                'name' => 'Order confirmed',
                'transitions' => [OrderStatusEnum::READY_FOR_DISPATCH->value, $outOfStock, $cancelled],
            ],
            OrderStatusEnum::READY_FOR_DISPATCH->value => [
                'name' => 'Preparing to ship',
                'transitions' => [OrderStatusEnum::DISPATCHED->value, $cancelled],
            ],
            OrderStatusEnum::DISPATCHED->value => [
                'name' => 'Shipped',
                'transitions' => [OrderStatusEnum::DELIVERED->value, OrderStatusEnum::RETURNED->value, $cancelled],
            ],
            OrderStatusEnum::DELIVERED->value => ['name' => 'Delivered', 'is_final' => true],
            OrderStatusEnum::RETURNED->value => ['name' => 'Returned', 'is_final' => true],
            $outOfStock => ['name' => 'Out of stock', 'is_final' => true],
            $cancelled => ['name' => 'Cancelled', 'is_final' => true],
        ];
    }

    private function assertPaidTransitionExists(array $statuses): void
    {
        $bySlug = collect($statuses)->keyBy('slug');
        $verifying = $bySlug->get(OrderStatusEnum::VERIFYING->value);
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
