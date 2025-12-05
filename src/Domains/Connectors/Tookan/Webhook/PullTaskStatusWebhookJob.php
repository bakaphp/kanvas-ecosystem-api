<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Webhook;

use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Tookan\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Actions\UpdateOrderAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class PullTaskStatusWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $tookanSharedSecret = $this->receiver->configuration['tookan_shared_secret'] ?? null;

        // Validate shared secret
        if (! isset($payload['tookan_shared_secret']) || $payload['tookan_shared_secret'] !== $tookanSharedSecret) {
            Log::error('Invalid tookan_shared_secret', [
                'received' => $payload['tookan_shared_secret'] ?? 'missing',
                'expected' => $tookanSharedSecret,
            ]);
            throw new Exception('Invalid shared secret');
        }

        // Extract order ID and Tookan task status
        $orderId = $payload['order_id'] ?? null;
        $tookanJobStatus = $payload['job_status'] ?? null; // Tookan status code

        if (! $orderId) {
            Log::error('Missing order_id in Tookan webhook', ['payload' => $payload]);
            throw new Exception('Missing order_id in payload');
        }

        if ($tookanJobStatus === null) {
            Log::error('Missing job_status in Tookan webhook', ['payload' => $payload]);
            throw new Exception('Missing job_status in payload');
        }

        // Find the order
        $order = Order::fromApp($this->receiver->app)
            ->where('id', $orderId)
            ->firstOrFail();

        // Map Tookan status to internal order status
        $newStatus = $this->mapTookanStatusToOrderStatus($tookanJobStatus);

        if ($newStatus) {
            // Update the order status
            $updateAction = new UpdateOrderAction(
                $order,
                [
                    'status' => $newStatus,
                    'metadata' => [
                        'tookan' => [
                            'last_webhook_status' => $tookanJobStatus,
                            'last_webhook_received_at' => now()->toIso8601String(),
                        ],
                    ],
                ],
                $this->receiver->user
            );

            $updateAction->execute();
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
