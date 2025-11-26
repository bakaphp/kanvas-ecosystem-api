<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Webhooks;

use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Webhook handler for Universal Assistance insurance order completion events.
 * Receives and processes insurance data from external webhook for any insurance product.
 */
class ProcessUniversalAssistanceOrderWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        // Validate webhook payload structure
        $this->validateWebhookPayload($payload);

        // Extract order ID from order_info
        $orderId = $payload['order_info']['order_id'] ?? null;

        if (! $orderId) {
            throw new Exception('Order ID not found in webhook payload');
        }

        // Find the order
        $order = $this->findOrder((string) $orderId);

        if (! $order) {
            throw new Exception("Order not found for ID: {$orderId}");
        }

        // Store the webhook data in the order metadata
        $this->storeWebhookDataInOrder($order, $payload);

        // Fire workflow to trigger ProcessInsuranceCartActivity
        $order->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
            [
                'app' => $order->app,
                'company' => $order->company,
            ]
        );

        $eventType = $payload['event'] ?? 'unknown';

        return [
            'message' => 'Universal Assistance order processed successfully',
            'event' => $eventType,
            'order_id' => $order->getId(),
            'order_uuid' => $order->uuid,
        ];
    }

    /**
     * Validate the webhook payload structure.
     *
     * @throws Exception
     */
    protected function validateWebhookPayload(array $payload): void
    {
        // Validate event exists
        if (! isset($payload['event'])) {
            throw new Exception('Missing event type in webhook payload');
        }

        // Validate required sections
        if (! isset($payload['insurance'])) {
            throw new Exception('Missing insurance data in webhook payload');
        }

        if (! isset($payload['order_info'])) {
            throw new Exception('Missing order_info in webhook payload');
        }

        // Validate titular structure
        if (! isset($payload['insurance']['titular'])) {
            throw new Exception('Missing titular data in insurance payload');
        }

        // Validate required titular fields
        $titular = $payload['insurance']['titular'];
        $requiredFields = [
            'firstname',
            'lastname',
            'dob',
            'sex',
            'activationDate',
            'originCountryCode',
            'destinationCountryCode',
        ];

        foreach ($requiredFields as $field) {
            if (! isset($titular[$field])) {
                throw new Exception("Missing required field in titular: {$field}");
            }
        }

        // Validate dependents structure if present
        if (isset($payload['insurance']['dependents']) && ! is_array($payload['insurance']['dependents'])) {
            throw new Exception('Dependents must be an array');
        }
    }

    /**
     * Find the order by ID.
     */
    protected function findOrder(string $orderId): ?Order
    {
        return Order::fromApp($this->receiver->app)
            ->where('id', $orderId)
            ->first();
    }

    /**
     * Store the webhook data in the order metadata.
     * Transforms the insurance webhook data into the format expected by ProcessInsuranceCartActivity.
     */
    protected function storeWebhookDataInOrder(Order $order, array $payload): void
    {
        $insuranceData = $payload['insurance'];
        $planInfo = $payload['plan_info'] ?? [];
        $orderInfo = $payload['order_info'] ?? [];
        $eventType = $payload['event'] ?? 'insurance_order_completed';

        // Transform the insurance data to match the internal structure
        $transformedInsurance = $this->transformInsuranceDataFormat($insuranceData, $planInfo, $orderInfo);

        // Get current metadata
        $metadata = $order->metadata ?? [];
        if (is_object($metadata)) {
            $metadata = json_decode(json_encode($metadata), true);
        }

        // Store the transformed insurance data without messageId
        // ProcessInsuranceCartActivity will find the correct messageId using its fallback logic
        $metadata['new_data']['data']['insurancePendingData'][] = [
            'insurance' => $transformedInsurance,
        ];

        // Store original webhook data for reference
        if (! isset($metadata['webhook_data'])) {
            $metadata['webhook_data'] = [];
        }

        $metadata['webhook_data'][$eventType] = [
            'received_at' => $payload['timestamp'] ?? date('Y-m-d H:i:s'),
            'payload' => $payload,
        ];

        // Update order metadata
        $order->metadata = $metadata;
        $order->saveOrFail();
    }

    /**
     * Transform the insurance webhook data format to the internal insurance structure.
     */
    protected function transformInsuranceDataFormat(array $insuranceData, array $planInfo, array $orderInfo): array
    {
        $titular = $insuranceData['titular'];

        // Transform titular data
        $transformedTitular = $this->transformPersonDataFromWebhook($titular, true, $planInfo);

        // Transform dependents if present
        $transformedDependents = [];
        if (isset($insuranceData['dependents']) && is_array($insuranceData['dependents'])) {
            foreach ($insuranceData['dependents'] as $dependent) {
                $transformedDependents[] = $this->transformPersonDataFromWebhook($dependent, false, $planInfo);
            }
        }

        return [
            'titular' => $transformedTitular,
            'dependents' => $transformedDependents,
        ];
    }

    /**
     * Transform a person's data from webhook format to internal format.
     */
    protected function transformPersonDataFromWebhook(array $personData, bool $isTitular, array $planInfo): array
    {
        // Country codes are already in the correct format
        $originCountryCode = $personData['originCountryCode'];
        $destinationCountryCode = $personData['destinationCountryCode'];

        // Extract plan variant from plan info
        $planVariant = $this->extractPlanVariant($planInfo);

        // Normalize sex to uppercase
        $sex = strtoupper($personData['sex']);

        // Build the transformed data structure
        $transformed = [
            'firstname' => $personData['firstname'],
            'lastname' => $personData['lastname'],
            'email' => $personData['email'] ?? '',
            'phone' => $personData['phone'] ?? '',
            'dob' => $personData['dob'],
            'sex' => $sex,
            'activationDate' => $personData['activationDate'],
            'expirationDate' => $personData['expirationDate'],
            'originCountryCode' => $originCountryCode,
            'destinationCountryCode' => $destinationCountryCode,
            'variant' => $planVariant,
        ];

        // Add ID fields if present
        if (isset($personData['idType'])) {
            $transformed['idType'] = $personData['idType'];
        }

        if (isset($personData['idNumber'])) {
            $transformed['idNumber'] = $personData['idNumber'];
        }

        // Add plan info from person data if present (titular already has plan in webhook)
        if ($isTitular && isset($personData['plan'])) {
            $transformed['plan'] = $personData['plan'];
        }

        return $transformed;
    }

    /**
     * Extract plan variant from plan info or person data.
     */
    protected function extractPlanVariant(array $planInfo): string
    {
        if (empty($planInfo)) {
            return 'basic';
        }

        // Check is_unlimited flag - if present, use it directly
        if (isset($planInfo['is_unlimited'])) {
            return $planInfo['is_unlimited'] ? 'unlimited' : 'basic';
        }

        // Default to basic
        return 'basic';
    }
}
