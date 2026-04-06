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

                $orderNumber = $order->parent?->order_number ?? $order->order_number;

                $companyNotificationsStatuses = [
                    OrderStatusEnum::RECEIVED->value,
                    OrderStatusEnum::PREPARING->value,
                    OrderStatusEnum::READY_FOR_PICKUP->value,
                    OrderStatusEnum::DISPATCHED->value,
                    OrderStatusEnum::DELIVERED->value,
                    OrderStatusEnum::CANCELLED->value,
                ];

                $providerSubjects = [
                    OrderStatusEnum::RECEIVED->value         => "New Order · #{$orderNumber}",
                    OrderStatusEnum::PREPARING->value        => "Order confirmed · Orden #{$orderNumber}",
                    OrderStatusEnum::READY_FOR_PICKUP->value => "Pickup in Progress · Orden #{$orderNumber}",
                    OrderStatusEnum::DISPATCHED->value       => "Shipped · Orden #{$orderNumber}",
                    OrderStatusEnum::DELIVERED->value        => "Order Delivered · Orden #{$orderNumber}",
                    OrderStatusEnum::CANCELLED->value        => "Order Canceled · #{$orderNumber}",
                ];

                if ($toStatus == OrderStatusEnum::READY_FOR_PICKUP->value) {
                    $parentOrder = $order->parent;

                    if ($parentOrder) {
                        $hasPackaging = $parentOrder->items->contains(
                            fn ($item) => $item->variant->companies_id === $parentOrder->companies_id
                        );

                        new CreateTookanTaskAction(
                            $order,
                            $order->company,
                            $hasPackaging ? $parentOrder->company : null,
                            $hasPackaging ? null : $parentOrder->user
                        )->execute();
                    }
                }

                // Send notifications to provider (child order's company) — email + in-app
                if (in_array($toStatus, $companyNotificationsStatuses)) {
                    $template = 'provider-' . strtolower($toStatus);
                    (new SendOrderEmailsAction(
                        $order,
                        $template,
                        [],
                        ['mail', 'database'],
                        $providerSubjects[$toStatus] ?? null,
                    ))->execute();
                }

                // Update parent order status based on child order progress
                $isCascading = $this->updateParentOrderIfNeeded($order, $toStatus);

                return [
                    'order' => $order->getId(),
                    'order_type' => 'child',
                    'parent_order_id' => $order->parent_id,
                    'parent_order_number' => $order->parent?->order_number,
                    'status' => 'success',
                    'message' => 'Child order status transition handled successfully',
                    'data' => [
                        'child_order' => $order->orderStatus?->name,
                        'parent_order' => $isCascading ? $order->parent->orderStatus?->name ?? null : null,
                        'order_number' => $order?->order_number,
                        'is_cascading' => $isCascading,
                    ],
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
    private function updateParentOrderIfNeeded(Model $order, ?string $toStatus): bool
    {
        $parentOrder = $order->parent;
        if (! $parentOrder) {
            return false;
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
            // Only update parent if it's not already at the target status
            $parentAlreadyAtStatus = $parentOrder->orderStatus->slug === $toStatus;

            if (! $parentAlreadyAtStatus) {
                // Update parent order status to match child order progress
                $orderRepository = new OrderRepository($order);
                $status = $orderRepository->getStatus($toStatus);
                $transitionCompanyStatus = new TransitionOrderStateAction(
                    $parentOrder,
                    $parentOrder->user,
                    $status
                );
                $transitionCompanyStatus->execute();
                return true;
            }
        }

        return false;
    }
}
