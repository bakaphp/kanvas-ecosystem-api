<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Webhook;

use Exception;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\SendOrderEmailsAction;
use Kanvas\Souk\Orders\Actions\TransitionOrderStateAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Repositories\OrderRepository;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullTaskStatusWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $tookanSharedSecret = $this->receiver->configuration['tookan_shared_secret'] ?? null;

        // Validate shared secret only when one is configured on the receiver
        if ($tookanSharedSecret !== null && ($payload['tookan_shared_secret'] ?? null) !== $tookanSharedSecret) {
            throw new Exception('Invalid shared secret');
        }

        // Extract order ID and Tookan task status
        $orderId = $payload['order_id'] ?? null;
        $tookanJobStatus = isset($payload['job_status']) ? (int) $payload['job_status'] : null;

        if (! $orderId) {
            throw new Exception('Missing order_id in payload');
        }

        if ($tookanJobStatus === null) {
            throw new Exception('Missing job_status in payload');
        }

        $order = Order::fromApp($this->receiver->app)
            ->where('id', $orderId)
            ->firstOrFail();

        $isMainOrder = $order->parent_id === null;
        $newStatus = $this->mapTookanStatusToOrderStatus($tookanJobStatus);
        $orderRepository = new OrderRepository($order);

        if (! $isMainOrder && $newStatus) {
            $mainOrder = Order::fromApp($this->receiver->app)
                ->where('id', $order->parent_id)
                ->with('items.variant')
                ->first();

            $hasPackaging = $mainOrder?->items->contains(
                fn ($item) => $item->variant->companies_id === $mainOrder->companies_id
            ) ?? false;

            $mainOrderRepository = $mainOrder ? new OrderRepository($mainOrder) : null;

            switch ($newStatus) {
                case OrderStatusEnum::DISPATCHED->value:
                    $status = $orderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                    new TransitionOrderStateAction($order, $this->receiver->user, $status)->execute();

                    if ($mainOrder && $hasPackaging) {
                        // Rider heading to main company for custom wrapping
                        $notification = new SendOrderEmailsAction(
                            $mainOrder,
                            'owner-ready_for_pickup',
                            [],
                            ['mail'],
                            "Ready for Wrapping · Orden #{$mainOrder->order_number}",
                        );
                        $notification->execute();
                    } elseif ($mainOrder) {
                        // No wrapping — rider heading directly to end user, transition main order to dispatched
                        $parentStatus = $mainOrderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                        new TransitionOrderStateAction(
                            $mainOrder,
                            $this->receiver->user,
                            $parentStatus
                        )->execute();
                    }
                    break;
                case OrderStatusEnum::DELIVERED->value:
                    if ($hasPackaging) {
                        // Rider arrived at main company with the product for wrapping
                        $status = $mainOrderRepository->getStatus(OrderStatusEnum::PREPARING_PACKAGING->value);
                        new TransitionOrderStateAction(
                            $mainOrder,
                            $this->receiver->user,
                            $status
                        )->execute();
                    } elseif ($mainOrder) {
                        // No wrapping — rider delivered directly to end user
                        $mainOrder->updateQuietly(['shipped_date' => now()->toDateTimeString()]);
                        $parentStatus = $mainOrderRepository->getStatus(OrderStatusEnum::DELIVERED->value);
                        new TransitionOrderStateAction(
                            $mainOrder,
                            $this->receiver->user,
                            $parentStatus
                        )->execute();
                    }
                    break;
                default:
                    return [
                        'message' => 'Tookan webhook processed successfully - no status update',
                        'order_id' => $orderId,
                        'tookan_status' => $tookanJobStatus,
                        'mapped_status' => null,
                    ];
            };
        } elseif ($isMainOrder) {
            $providerOrder = Order::fromApp($this->receiver->app)
                ->where('parent_id', $order->id)
                ->first();

            if (! $providerOrder) {
                return [
                    'message' => 'Tookan webhook processed successfully - no provider order found',
                    'order_id' => $orderId,
                    'tookan_status' => $tookanJobStatus,
                    'mapped_status' => $newStatus,
                ];
            }

            $providerOrderRepository = new OrderRepository($providerOrder);

            switch ($newStatus) {
                case OrderStatusEnum::DISPATCHED->value:
                    // Rider picked up from main company, heading to end customer — move both orders to dispatched
                    $providerStatus = $providerOrderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                    new TransitionOrderStateAction(
                        $providerOrder,
                        $this->receiver->user,
                        $providerStatus
                    )->execute();

                    $parentStatus = $orderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                    new TransitionOrderStateAction(
                        $order,
                        $this->receiver->user,
                        $parentStatus
                    )->execute();
                    break;
                case OrderStatusEnum::DELIVERED->value:
                    // Rider delivered to end customer — move main order to delivered
                    $order->updateQuietly(['shipped_date' => now()->toDateTimeString()]);
                    $parentStatus = $orderRepository->getStatus(OrderStatusEnum::DELIVERED->value);
                    new TransitionOrderStateAction(
                        $order,
                        $this->receiver->user,
                        $parentStatus
                    )->execute();
                    break;
                default:
                    return [
                        'message' => 'Tookan webhook processed successfully - no status update',
                        'order_id' => $orderId,
                        'tookan_status' => $tookanJobStatus,
                        'mapped_status' => null,
                    ];
            };
        }

        return [
            'message' => 'Tookan webhook processed successfully',
            'order_id' => $orderId,
            'tookan_status' => $tookanJobStatus,
            'mapped_status' => $newStatus,
        ];
    }

    /**
     * Map Tookan job status codes to internal order statuses.
     *
     * Tookan status codes:
     * 0 = Assigned
     * 1 = Started
     * 2 = Successful
     * 3 = Failed
     * 4 = In Progress
     * 5 = Unassigned
     * 6 = Accepted
     * 7 = Declined
     * 8 = Cancelled
     * 9 = Deleted
     * 10 = On Hold
     */
    private function mapTookanStatusToOrderStatus(int $tookanStatus): ?string
    {
        return match ($tookanStatus) {
            0, 6 => OrderStatusEnum::READY_FOR_PICKUP->value,  // Assigned/Accepted
            1, 4 => OrderStatusEnum::DISPATCHED->value,         // Started/In Progress
            2 => OrderStatusEnum::DELIVERED->value,             // Successful
            // 3, 7, 8, 9 => OrderStatusEnum::CANCELLED->value,    // Failed/Declined/Cancelled/Deleted
            default => null, // Don't update for other statuses
        };
    }
}
