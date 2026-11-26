<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Dhl\Enums\BookingStateEnum;
use Kanvas\Connectors\Dhl\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Enums\DeliveryOrderStatusEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction(
    name: 'DHL Flag Cancelled Shipment',
    description: 'Flags a cancelled order whose DHL shipment was already booked for manual review and emails the owner, '
        . 'since DHL cannot void a booking and staff must cancel the pickup in MyDHL. Does nothing for an order that was never booked.',
    integration: IntegrationsEnum::DHL,
)]
class FlagCancelledDhlShipmentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string MANUAL_REVIEW_TEMPLATE = 'owner-order-manual-review';

    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::DHL,
            additionalParams: $params,
            integrationOperation: function (
                Model $order,
                AppInterface $app,
                mixed $integrationCompany,
                array $additionalParams
            ): array {
                if (($additionalParams['to_status'] ?? null) !== DeliveryOrderStatusEnum::CANCELLED->value) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'Not a cancelled transition'];
                }

                if ($order->get(CustomFieldEnum::BOOKING_STATE->value) !== BookingStateEnum::BOOKED->value) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'The shipment was never booked'];
                }

                $order->set(CustomFieldEnum::BOOKING_STATE->value, BookingStateEnum::MANUAL_REVIEW->value);

                new SendOrderEmailsAction(
                    $order,
                    self::MANUAL_REVIEW_TEMPLATE,
                    ['tracking_number' => $order->tracking_client_id],
                    ['mail'],
                )->execute();

                return ['order' => $order->getId(), 'status' => 'success', 'flagged' => true];
            },
            company: $order->company,
        );
    }
}
