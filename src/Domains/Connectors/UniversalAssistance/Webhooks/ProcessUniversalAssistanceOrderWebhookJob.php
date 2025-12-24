<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Webhooks;

use Exception;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * Webhook handler for Universal Assistance insurance order completion events.
 * Receives and processes insurance data from external webhook for any insurance product.
 * Supports multiple eSIMs per order, each with its own titular and dependents.
 */
class ProcessUniversalAssistanceOrderWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        // Validate webhook payload structure
        $this->validateWebhookPayload($payload);

        // Extract all ICCIDs from esims array
        $iccids = $this->extractIccidsFromPayload($payload);

        if (empty($iccids)) {
            throw new Exception('No ICCIDs found in webhook payload');
        }

        // Find the order by any of the ICCIDs (all should belong to the same order)
        $order = $this->findOrderByIccids($iccids);

        if (! $order) {
            throw new Exception('Order not found for ICCIDs: ' . implode(', ', $iccids));
        }

        // Store the webhook data in the order metadata (one entry per eSIM)
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
        $esimCount = count($payload['esims']);

        return [
            'message' => 'Universal Assistance order processed successfully',
            'event' => $eventType,
            'esims_processed' => $esimCount,
            'iccids' => $iccids,
            'order_id' => $order->getId(),
            'order_uuid' => $order->uuid,
        ];
    }

    /**
     * Extract all ICCIDs from the esims array in payload.
     */
    protected function extractIccidsFromPayload(array $payload): array
    {
        $iccids = [];

        if (isset($payload['esims']) && is_array($payload['esims'])) {
            foreach ($payload['esims'] as $esim) {
                if (isset($esim['iccid']) && ! empty($esim['iccid'])) {
                    $iccids[] = $esim['iccid'];
                }
            }
        }

        return $iccids;
    }

    /**
     * Validate the webhook payload structure for multiple eSIMs.
     *
     * @throws Exception
     */
    protected function validateWebhookPayload(array $payload): void
    {
        // Validate event exists
        if (! isset($payload['event'])) {
            throw new Exception('Missing event type in webhook payload');
        }

        // Validate esims array exists
        if (! isset($payload['esims']) || ! is_array($payload['esims'])) {
            throw new Exception('Missing or invalid esims array in webhook payload');
        }

        if (empty($payload['esims'])) {
            throw new Exception('esims array cannot be empty');
        }

        // Validate each eSIM in the array
        foreach ($payload['esims'] as $index => $esim) {
            $this->validateEsimData($esim, $index);
        }

        // Validate order_info exists
        if (! isset($payload['order_info'])) {
            throw new Exception('Missing order_info in webhook payload');
        }
    }

    /**
     * Validate a single eSIM's data structure.
     *
     * @throws Exception
     */
    protected function validateEsimData(array $esim, int $index): void
    {
        // Validate ICCID exists
        if (! isset($esim['iccid']) || empty($esim['iccid'])) {
            throw new Exception("Missing iccid in esim at index {$index}");
        }

        // Validate insurance data exists
        if (! isset($esim['insurance'])) {
            throw new Exception("Missing insurance data in esim at index {$index}");
        }

        // Validate titular structure
        if (! isset($esim['insurance']['titular'])) {
            throw new Exception("Missing titular data in esim at index {$index}");
        }

        // Validate required titular fields
        $titular = $esim['insurance']['titular'];
        $requiredFields = [
            'firstname',
            'lastname',
            'dob',
            'sex',
            'activationDate',
            'expirationDate',
            'originCountryCode',
            'destinationCountryCode',
        ];

        foreach ($requiredFields as $field) {
            if (! isset($titular[$field])) {
                throw new Exception("Missing required field '{$field}' in titular of esim at index {$index}");
            }
        }

        // Validate dependents structure if present
        if (isset($esim['insurance']['dependents']) && ! is_array($esim['insurance']['dependents'])) {
            throw new Exception("Dependents must be an array in esim at index {$index}");
        }
    }

    /**
     * Find the order by any of the ICCIDs through OrderItem.
     * All ICCIDs should belong to the same order.
     */
    protected function findOrderByIccids(array $iccids): ?Order
    {
        // Find the first order item matching any ICCID
        $orderItem = OrderItem::where('apps_id', $this->receiver->app->getId())
            ->whereIn('product_sku', $iccids)
            ->where('is_deleted', false)
            ->first();

        if (! $orderItem) {
            return null;
        }

        return $orderItem->order;
    }

    /**
     * Store the webhook data in the order metadata.
     * Creates one insurancePendingData entry per eSIM for the Activity to process.
     */
    protected function storeWebhookDataInOrder(Order $order, array $payload): void
    {
        $eventType = $payload['event'] ?? 'insurance_order_completed';
        $orderInfo = $payload['order_info'] ?? [];

        // Get current metadata
        $metadata = $order->metadata ?? [];
        if (is_object($metadata)) {
            $metadata = json_decode(json_encode($metadata), true);
        }

        // Initialize insurancePendingData array if not exists
        if (! isset($metadata['new_data']['data']['insurancePendingData'])) {
            $metadata['new_data']['data']['insurancePendingData'] = [];
        }

        // Process each eSIM and add to insurancePendingData
        foreach ($payload['esims'] as $esim) {
            $insuranceData = $esim['insurance'];
            $planInfo = $esim['plan_info'] ?? [];
            $iccid = $esim['iccid'];

            // Transform the insurance data to match the internal structure
            $transformedInsurance = $this->transformInsuranceDataFormat($insuranceData, $planInfo);

            // Add entry for this eSIM (Activity will handle messageId fallback)
            $metadata['new_data']['data']['insurancePendingData'][] = [
                'insurance' => $transformedInsurance,
                'messageId' => null, // Activity has fallback logic to find the correct messageId
                'iccid' => $iccid,   // Store for reference/debugging
            ];
        }

        // Store original webhook data for reference
        if (! isset($metadata['webhook_data'])) {
            $metadata['webhook_data'] = [];
        }

        $metadata['webhook_data'][$eventType] = [
            'received_at' => $payload['timestamp'] ?? date('Y-m-d H:i:s'),
            'payload' => $payload,
            'esims_count' => count($payload['esims']),
        ];

        // Update order metadata
        $order->metadata = $metadata;
        $order->saveOrFail();
    }

    /**
     * Transform the insurance webhook data format to the internal insurance structure.
     */
    protected function transformInsuranceDataFormat(array $insuranceData, array $planInfo): array
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
