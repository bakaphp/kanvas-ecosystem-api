<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'DHL Order Status Notice',
    description: 'Emails the customer at every DHL order status except dispatched (sent by the booking with the tracking number), '
        . 'emails the owner when an order is placed, delivered, returned or cancelled, '
        . 'and flags a cancelled order whose DHL shipment was already booked for manual review, since DHL cannot void a booking.',
    integration: IntegrationsEnum::DHL,
)]
class NotifyOrderStatusActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string CUSTOMER_TEMPLATE_PREFIX = 'user-order-';
    public const string OWNER_TEMPLATE_PREFIX = 'owner-order-';
    public const string MANUAL_REVIEW_TEMPLATE = 'owner-order-manual-review';
    public const array NOTIFIED_STATUSES = [
        OrderStatusEnum::VERIFYING,
        OrderStatusEnum::PAID,
        OrderStatusEnum::READY_FOR_DISPATCH,
        OrderStatusEnum::DELIVERED,
        OrderStatusEnum::RETURNED,
        OrderStatusEnum::OUT_OF_STOCK,
        OrderStatusEnum::CANCELLED,
    ];
    public const array OWNER_NOTIFIED_STATUSES = [
        OrderStatusEnum::VERIFYING,
        OrderStatusEnum::DELIVERED,
        OrderStatusEnum::RETURNED,
        OrderStatusEnum::CANCELLED,
    ];

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::DHL,
            additionalParams: $params,
            integrationOperation: function (Model $order, AppInterface $app, mixed $integrationCompany, array $additionalParams): array {
                $status = OrderStatusEnum::tryFrom((string) ($additionalParams['to_status'] ?? ''));

                if ($status === null || ! in_array($status, self::NOTIFIED_STATUSES, true)) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'Status is not notified'];
                }

                new SendOrderEmailsAction(
                    $order,
                    self::CUSTOMER_TEMPLATE_PREFIX . $status->value,
                    [],
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

                if ($status === OrderStatusEnum::CANCELLED) {
                    $this->flagBookedShipmentForReview($order);
                }

                return ['order' => $order->getId(), 'status' => 'success', 'notified' => $status->value];
            },
            company: $order->company,
        );
    }

    private function flagBookedShipmentForReview(Model $order): void
    {
        if ($order->get(CustomFieldEnum::BOOKING_STATE->value) !== BookingStateEnum::BOOKED->value) {
            return;
        }

        $order->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::MANUAL_REVIEW->value);

        new SendOrderEmailsAction(
            $order,
            self::MANUAL_REVIEW_TEMPLATE,
            ['tracking_number' => $order->tracking_client_id],
            ['mail'],
        )->execute();
    }
}
