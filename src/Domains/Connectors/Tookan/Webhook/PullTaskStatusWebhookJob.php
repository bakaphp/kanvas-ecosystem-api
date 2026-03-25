<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Webhook;

use Exception;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
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

        // Find the order

        $order = Order::fromApp($this->receiver->app)
            ->where('id', $orderId)
            ->firstOrFail();

        $isGifteaOrder = $order->parent_id == null;

        //  if it is a giftea order it means we neet to update the company order status as well
        $newStatus = $this->mapTookanStatusToOrderStatus($tookanJobStatus);
        $orderRepository = new OrderRepository($order);

        if (! $isGifteaOrder && $newStatus) {
            $gifteaOrder = Order::fromApp($this->receiver->app)
                ->where('id', $order->parent_id)
                ->first();


            switch ($newStatus) {
                case OrderStatusEnum::DELIVERED->value:
                    $status = $orderRepository->getStatus(OrderStatusEnum::PREPARING_PACKAGING->value);

                    $transitionCompanyStatus = new TransitionOrderStateAction(
                        $gifteaOrder,
                        $this->receiver->user,
                        $status
                    );
                    $transitionCompanyStatus->execute();
                    break;
                case OrderStatusEnum::DISPATCHED->value:
                    $status = $orderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                    $transitionCompanyStatus = new TransitionOrderStateAction(
                        $order,
                        $this->receiver->user,
                        $status
                    );
                    $transitionCompanyStatus->execute();
                    break;
                default:
                    // for other statuses we do not update the company order
                    return [
                        'message' => 'Tookan webhook processed successfully - no status update for giftea order',
                        'order_id' => $orderId,
                        'tookan_status' => $tookanJobStatus,
                        'mapped_status' => null,
                    ];
            };
        } elseif ($isGifteaOrder) {
            // for giftea orders we need to update the company order
            $companyOrder = Order::fromApp($this->receiver->app)
                ->where('parent_id', $order->id)
                ->first();

            if (! $companyOrder) {
                return [
                    'message' => 'Tookan webhook processed successfully - no child order found',
                    'order_id' => $orderId,
                    'tookan_status' => $tookanJobStatus,
                    'mapped_status' => $newStatus,
                ];
            }

            switch ($newStatus) {
                case OrderStatusEnum::DELIVERED->value:
                case OrderStatusEnum::DISPATCHED->value:
                    $status = $orderRepository->getStatus(OrderStatusEnum::DISPATCHED->value);
                    $transitionCompanyStatus = new TransitionOrderStateAction(
                        $companyOrder,
                        $this->receiver->user,
                        $status
                    );
                    $transitionCompanyStatus->execute();
                    break;
                default:
                    return [
                        'message' => 'Tookan webhook processed successfully - no status update for giftea order',
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
