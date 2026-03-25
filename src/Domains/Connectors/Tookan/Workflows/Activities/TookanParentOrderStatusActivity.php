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
                if ($order->parent_id !== null) {
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
                if ($toStatus == OrderStatusEnum::PACKAGING_READY->value) {
                    (new CreateTookanTaskAction(
                        $order,
                        $order->company,
                        null, // assuming delivery to customer, so no specific user
                        $order->user
                    ))->execute();
                }

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

                $childOrder = $order->children->first();
                $isDelivered = $childOrder?->orderStatus->slug === OrderStatusEnum::DELIVERED->value;
                $isCascading = false;

                if ($toStatus == OrderStatusEnum::DELIVERED->value && $childOrder && ! $isDelivered) {
                    $orderRepository = new OrderRepository($order);
                    $status = $orderRepository->getStatus($toStatus);
                    $transitionCompanyStatus = new TransitionOrderStateAction(
                        $childOrder,
                        $childOrder->user,
                        $status
                    );
                    $transitionCompanyStatus->execute();
                    $isCascading = true;
                }

                return [
                    'order' => $order->getId(),
                    'order_type' => 'parent',
                    'status' => 'success',
                    'message' => 'Parent order status transition handled successfully',
                    'data' => [
                        'parent_order' => $order->orderStatus?->name,
                        'order_id' => $order?->getId(),
                        'order_number' => $order?->order_number,
                        'child_order' => $childOrder?->orderStatus?->name ?? null,
                        'is_cascading' => $isCascading,
                    ],
                    'response' => $childOrder?->orderStatus->toArray(),
                ];
            },
            company: $order->company,
        );
    }
}
