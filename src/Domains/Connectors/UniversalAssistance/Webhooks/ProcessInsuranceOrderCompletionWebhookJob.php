<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Webhooks;

use Exception;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Webhook job to handle insurance order completion notifications
 */
class ProcessInsuranceOrderCompletionWebhookJob extends ProcessWebhookJob
{
    protected int $failedReturnHttpCode = 422;

    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        // Validate webhook structure
        $this->validateWebhookPayload($payload);

        // Extract order information
        $orderInfo = $payload['order_info'] ?? [];
        $orderId = $orderInfo['order_id'] ?? null;

        if (! $orderId) {
            throw new ValidationException('Order ID is required in webhook payload');
        }

        // Find the order in the system
        $order = $this->findOrder($orderId);

        if (! $order) {
            throw new ValidationException("Order not found with ID: {$orderId}");
        }

        // Store webhook data in order metadata
        $this->storeWebhookDataInOrder($order, $payload);

        return [
            'status' => 200,
            'message' => 'Insurance order completion processed successfully',
            'order_id' => $order->getId(),
            'webhook_event' => $payload['event'] ?? 'insurance_order_completed',
            'metadata_updated' => true,
        ];
    }

    /**
     * Validate the webhook payload structure
     */
    protected function validateWebhookPayload(array $payload): void
    {
        // Check for required event type
        if (($payload['event'] ?? '') !== 'insurance_order_completed') {
            throw new ValidationException('Invalid event type. Expected: insurance_order_completed');
        }

        // Check for insurance data
        if (! isset($payload['insurance']) || ! is_array($payload['insurance'])) {
            throw new ValidationException('Insurance data is required in webhook payload');
        }

        // Check for titular data
        if (! isset($payload['insurance']['titular']) || ! is_array($payload['insurance']['titular'])) {
            throw new ValidationException('Titular data is required in insurance payload');
        }

        // Validate required titular fields
        $titular = $payload['insurance']['titular'];
        $requiredFields = ['firstname', 'lastname', 'dob', 'sex', 'idType', 'idNumber'];

        foreach ($requiredFields as $field) {
            if (empty($titular[$field])) {
                throw new ValidationException("Required field '{$field}' is missing in titular data");
            }
        }

        // Validate dependents if present
        if (isset($payload['insurance']['dependents'])) {
            if (! is_array($payload['insurance']['dependents'])) {
                throw new ValidationException('Dependents must be an array');
            }

            foreach ($payload['insurance']['dependents'] as $index => $dependent) {
                if (! is_array($dependent)) {
                    throw new ValidationException("Dependent at index {$index} must be an object");
                }

                foreach ($requiredFields as $field) {
                    if (empty($dependent[$field])) {
                        throw new ValidationException("Required field '{$field}' is missing in dependent data at index {$index}");
                    }
                }
            }
        }
    }

    /**
     * Find the order by ID in the system
     */
    protected function findOrder(string $orderId): ?Order
    {
        try {
            // Try to find by UUID first
            return Order::where('uuid', $orderId)
                ->where('companies_id', $this->receiver->company->getId())
                ->notDeleted()
                ->first();
        } catch (Exception $e) {
            try {
                // Fallback to find by ID if UUID fails
                return Order::where('id', (int) $orderId)
                    ->where('companies_id', $this->receiver->company->getId())
                    ->notDeleted()
                    ->first();
            } catch (Exception $e) {
                return null;
            }
        }
    }

    /**
     * Store webhook data in order metadata for ProcessInsuranceCartActivity to consume
     */
    protected function storeWebhookDataInOrder(Order $order, array $payload): void
    {
        // Get current metadata
        $currentMetadata = $order->metadata ?? [];

        // Store the complete webhook payload in metadata
        // This follows the pattern that ProcessInsuranceCartActivity expects
        $currentMetadata['insurance_webhook'] = $payload;

        // Also store just the insurance data for easier access
        $currentMetadata['insurance'] = $payload['insurance'] ?? [];

        // Store additional webhook info
        $currentMetadata['webhook_received_at'] = now()->toISOString();
        $currentMetadata['webhook_event'] = $payload['event'] ?? 'insurance_order_completed';

        // Store plan info if available
        if (isset($payload['plan_info'])) {
            $currentMetadata['plan_info'] = $payload['plan_info'];
        }

        // Update order metadata
        $order->metadata = $currentMetadata;
        $order->saveOrFail();
    }


}
