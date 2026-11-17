<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'Delivery Order Status Notice',
    description: 'Emails the customer when a delivery order reaches a new status: placed, confirmed, preparing to ship, shipped, '
        . 'delivered, returned, out of stock or cancelled. The shipped email carries the tracking number and tracking link when the order has them. '
        . 'Emails the owner when an order is placed, delivered, returned or cancelled. '
        . 'The preparing-to-ship email is skipped when the order has already moved past that status. '
        . 'It does not book the shipment or move the order to another status.',
)]
class NotifyDeliveryOrderStatusActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string RULE_NAME = 'Delivery order status notice';
    public const string TRACKING_URL_FIELD = 'tracking_url';
    public const string CUSTOMER_TEMPLATE_PREFIX = 'user-order-';
    public const string OWNER_TEMPLATE_PREFIX = 'owner-order-';
    public const array NOTIFIED_STATUSES = [
        DeliveryOrderStatusEnum::VERIFYING,
        DeliveryOrderStatusEnum::PAID,
        DeliveryOrderStatusEnum::READY_FOR_DISPATCH,
        DeliveryOrderStatusEnum::DISPATCHED,
        DeliveryOrderStatusEnum::DELIVERED,
        DeliveryOrderStatusEnum::RETURNED,
        DeliveryOrderStatusEnum::OUT_OF_STOCK,
        DeliveryOrderStatusEnum::CANCELLED,
    ];
    public const array OWNER_NOTIFIED_STATUSES = [
        DeliveryOrderStatusEnum::VERIFYING,
        DeliveryOrderStatusEnum::DELIVERED,
        DeliveryOrderStatusEnum::RETURNED,
        DeliveryOrderStatusEnum::CANCELLED,
    ];

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $status = DeliveryOrderStatusEnum::tryFrom((string) ($params['to_status'] ?? ''));

        if ($status === null || ! in_array($status, self::NOTIFIED_STATUSES, true)) {
            return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'Status is not notified'];
        }

        if ($status === DeliveryOrderStatusEnum::READY_FOR_DISPATCH && $this->hasMovedPast($order, $status)) {
            return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'The order already moved past ready-for-dispatch'];
        }

        new SendOrderEmailsAction(
            $order,
            self::CUSTOMER_TEMPLATE_PREFIX . $status->value,
            [
                'tracking_number' => $order->tracking_client_id,
                'tracking_url' => $order->get(self::TRACKING_URL_FIELD),
            ],
            ['mail', 'database'],
        )->execute();

        if (in_array($status, self::OWNER_NOTIFIED_STATUSES, true)) {
            new SendOrderEmailsAction(
                $order,
                self::OWNER_TEMPLATE_PREFIX . $status->value,
                ['tracking_number' => $order->tracking_client_id],
                ['mail'],
            )->execute();
        }

        return ['order' => $order->getId(), 'status' => 'success', 'notified' => $status->value];
    }

    private function hasMovedPast(Model $order, DeliveryOrderStatusEnum $status): bool
    {
        return $order->orderStatus()->value('slug') !== $status->value;
    }
}
