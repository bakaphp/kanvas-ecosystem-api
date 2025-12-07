<?php

namespace Kanvas\Connectors\Tookan\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Tookan\Actions\CreateTookanTaskAction;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Repositories\OrderRepository;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class TookanParentOrderStatusActivity extends KanvasActivity implements WorkflowActivityInterface
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

                // lets make sure it is a parent order
                if (! $order->parent_id == null) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a parent order, skipping sync',
                    ];
                }

                // Parent order notifications - send to end customer
                $userNotificationsStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::DISPATCHED->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                // Main company (parent company) notifications
                $mainCompanyStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                // Create Tookan task when parent order is ready for final delivery to customer
                // if ($toStatus == OrderStatusEnum::READY_FOR_PICKUP->value) {
                //     (new CreateTookanTaskAction($order, $order->company, ))->execute();
                // }

                // Send notifications to end customer
                if (in_array($toStatus, $userNotificationsStatuses)) {
                    $template = 'user-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, []))->execute();
                }

                // Send notifications to main company (owner)
                if (in_array($toStatus, $mainCompanyStatuses)) {
                    $template = 'owner-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, []))->execute();
                }

                if ($toStatus == OrderStatusEnum::DELIVERED->value) {
                    $orderRepository = new OrderRepository($order);
                    $status = $orderRepository->getStatus($toStatus);
                    $transitionCompanyStatus = new TransitionOrderStateAction(
                        $order,
                        $order->user,
                        $status
                    );
                    $transitionCompanyStatus->execute();
                }

                return [
                    'order' => $order->getId(),
                    'order_type' => 'parent',
                    'status' => 'success',
                    'message' => 'Parent order status transition handled successfully',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }
}
