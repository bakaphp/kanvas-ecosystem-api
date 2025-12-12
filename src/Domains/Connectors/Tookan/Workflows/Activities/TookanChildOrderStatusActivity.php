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

class TookanChildOrderStatusActivity extends KanvasActivity implements WorkflowActivityInterface
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

                // lets make sure it is a child order
                if (! $order->parent_id) {
                    return [
                        'order' => $order->getId(),
                        'status' => 'success',
                        'message' => 'Order is not a child order, skipping sync',
                    ];
                }

                // Provider (child order company) notifications
                $companyNotificationsStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    OrderStatusEnum::DISPATCHED->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];


                if ($toStatus == OrderStatusEnum::READY_FOR_PICKUP->value) {
                    // Load parent order to get parent company address
                    $parentOrder = $order->parent;
                    $hasPackaging = $parentOrder->items->contains(function ($item) use ($parentOrder) {
                        return $item->variant->companies_id === $parentOrder->companies_id;
                    });

                    $companyRecipient = $hasPackaging ? $parentOrder->company : null;
                    $userRecipient = $hasPackaging ? null : $parentOrder->user;

                    if ($parentOrder) {
                        // Create Tookan task for delivery from provider to parent company
                        new CreateTookanTaskAction(
                            $order,
                            $order->company,
                            $companyRecipient,
                            $userRecipient
                        )->execute();
                    }
                }

                // Send notifications to provider (child order's company)
                if (in_array($toStatus, $companyNotificationsStatuses)) {
                    $template = 'provider-' . strtolower($toStatus);
                    (new SendOrderEmailsAction($order, $template, []))->execute();
                }

                // Update parent order status based on child order progress
                $this->updateParentOrderIfNeeded($order, $toStatus);

                return [
                    'order' => $order->getId(),
                    'order_type' => 'child',
                    'parent_order_id' => $order->parent_id,
                    'status' => 'success',
                    'message' => 'Child order status transition handled successfully',
                    'data' => $order->toArray(),
                    'response' => $order->toArray(),
                ];
            },
            company: $order->company,
        );
    }

    /**
     * Update parent order status based on child order progress
     * This implements Phase 1: Provider updates OrderA -> cascades to ParentOrder
     */
    private function updateParentOrderIfNeeded(Model $order, ?string $toStatus): void
    {
        $parentOrder = $order->parent;
        if (! $parentOrder) {
            return;
        }

        // Define statuses that should cascade to parent order
        $cascadeStatuses = [
            OrderStatusEnum::CANCELLED->value,
            OrderStatusEnum::PREPARING->value,
            OrderStatusEnum::READY_FOR_PICKUP->value,
            OrderStatusEnum::DISPATCHED->value,
        ];

        // If the child order status is in cascade list, update parent
        if (in_array($toStatus, $cascadeStatuses)) {
            // Update parent order status to match child order progress
            $orderRepository = new OrderRepository($order);
            $status = $orderRepository->getStatus($toStatus);
            $transitionCompanyStatus = new TransitionOrderStateAction(
                $parentOrder,
                $parentOrder->user,
                $status
            );
            $transitionCompanyStatus->execute();
        }
    }
}
