<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Dhl\Actions\CreateDhlShipmentAction;
use Kanvas\Connectors\Dhl\Enums\OrderStatusEnum;
use Kanvas\Connectors\Dhl\Exceptions\DhlBookingException;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'DHL Create Shipment',
    description: 'Books a DHL Express shipment for a DHL order that reached ready-for-dispatch: replays the applied quote, '
        . 'stores the tracking number and label on the order, moves it to dispatched and emails the customer.',
    integration: IntegrationsEnum::DHL,
)]
class CreateDhlShipmentActivity extends KanvasActivity implements WorkflowActivityInterface
{
    public const string OWNER_FAILURE_TEMPLATE = 'owner-order-booking-failed';

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
                if (($additionalParams['to_status'] ?? null) !== OrderStatusEnum::READY_FOR_DISPATCH->value) {
                    return ['order' => $order->getId(), 'status' => 'skipped', 'message' => 'Not a ready-for-dispatch transition'];
                }

                try {
                    return new CreateDhlShipmentAction($order)->execute();
                } catch (DhlBookingException $exception) {
                    return $this->failBooking($order, $exception);
                } catch (Throwable $exception) {
                    report($exception);

                    return $this->failBooking($order, DhlBookingException::unconfirmed($exception->getMessage()));
                }
            },
            company: $order->company,
        );
    }

    private function failBooking(Model $order, DhlBookingException $exception): array
    {
        new SendOrderEmailsAction(
            $order,
            self::OWNER_FAILURE_TEMPLATE,
            ['reason' => $exception->getMessage()],
            ['mail'],
        )->execute();

        return $this->failWorkflow([
            'order' => $order->getId(),
            'message' => $exception->getMessage(),
        ]);
    }
}
