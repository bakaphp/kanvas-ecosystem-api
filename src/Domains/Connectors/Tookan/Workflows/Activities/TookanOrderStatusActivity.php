<?php

namespace Kanvas\Connectors\Tookan\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class TookanOrderStatusActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::TOOKAN,
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                $toStatus = $params['to_status'] ?? null;

                $userNotificationsStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::DISPATCHED->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                $companyNotificationsStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                $mainCompanyStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                if (in_array($toStatus, $userNotificationsStatuses)) {
                    $template  = 'user-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, [], ))->execute();
                }

                if (in_array($toStatus, $companyNotificationsStatuses)) {
                    $template  = 'provider-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, []))->execute();
                }

                if (in_array($toStatus, $mainCompanyStatuses)) {
                    $template  = 'owner-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, []))->execute();
                }

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'message' => 'Order status transition handled successfully',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }
}
