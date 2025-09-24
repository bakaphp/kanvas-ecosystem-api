<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalAssistance\Services\InsuranceWorkflowService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessInsuranceCartActivity extends KanvasActivity
{
    /**
     * Process insurance data from order metadata (same pattern as AeroAmbulancia)
     */
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::UNIVERSAL_ASSISTANCE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                sleep(30);
                $order->refresh(); // Ensure the order is up-to-date (same as AeroAmbulancia)
                $data = $this->getActivityData($order, $params);

                // Create service
                $service = new InsuranceWorkflowService($app, $order);

                // Process insurance workflow with insurance data directly
                $results = $service->processInsuranceWorkflow($data['insurance_data']);

                // Store results in eSim message and order metadata (same pattern as AeroAmbulancia)
                $this->storeUniversalAssistanceData($order, $data['message_id'], $results);

                return $results;
            },
            company: $order->company,
        );
    }

    /**
     * Get all required data for the activity (try both workflow params and order metadata)
     */
    protected function getActivityData(Order $order, array $params): array
    {
        $insuranceData = [];

        // Approach 1: Try workflow input params directly (for direct workflow calls)
        if (isset($params['titular']) || isset($params['insurance'])) {
            // If params has titular directly, use params as insurance data
            if (isset($params['titular'])) {
                $insuranceData = $params;
            }
            // If params has insurance key, extract from there
            elseif (isset($params['insurance'])) {
                $insuranceData = $params['insurance'];
            }
        }

        // Approach 2: Extract from order metadata (eSim workflow pattern)
        if (empty($insuranceData)) {
            $orderMetadata = $order->metadata ?? [];

            // Look in esims metadata (created by eSim workflow)
            if (isset($orderMetadata['esims']) && is_array($orderMetadata['esims'])) {
                foreach ($orderMetadata['esims'] as $esim) {
                    if (isset($esim['eSimDetails']['insurance'])) {
                        $insuranceData = $esim['eSimDetails']['insurance'];
                        break; // Use first insurance data found
                    }
                }
            }
        }

        // Approach 3: Direct metadata fallback locations
        if (empty($insuranceData)) {
            $orderMetadata = $order->metadata ?? [];
            $insuranceData = $orderMetadata['universal_assistance']['insurance'] ??
                           $order->getMetadata('insurance') ??
                           [];
        }

        // Validate that we have insurance data
        if (empty($insuranceData)) {
            throw new \Kanvas\Exceptions\ValidationException('Insurance data is required - not found in workflow params or order metadata');
        }

        if (! isset($insuranceData['titular'])) {
            throw new \Kanvas\Exceptions\ValidationException('Titular data is required in insurance data. Available keys: ' . implode(', ', array_keys($insuranceData)));
        }

        // Get eSim message ID from order (same way as AeroAmbulancia)
        $messageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);
        if (! $messageId) {
            throw new \Kanvas\Exceptions\ValidationException('eSim Message ID not found in order - required for Universal Assistance processing');
        }

        // Return insurance data directly (no cart wrapper needed)
        return [
            'insurance_data' => $insuranceData,
            'message_id' => $messageId,
        ];
    }

    /**
     * Store Universal Assistance data in both message and order (same pattern as AeroAmbulancia)
     */
    protected function storeUniversalAssistanceData(Order $order, int $messageId, array $results): void
    {
        // Prepare universalAssistanceData structure (similar to aeroAmbulanciaData)
        $universalAssistanceData = [
            'processed_at' => now()->toISOString(),
            'workflow_type' => 'single_voucher_per_titular',
            'holder' => null,
            'dependents' => [],
            'summary' => [
                'titular_processed' => isset($results['titular']),
                'dependents_stored' => isset($results['dependents']) ? count($results['dependents']) : 0,
                'total_vouchers_created' => isset($results['titular']) ? 1 : 0,
                'total_dependents_in_metadata' => isset($results['dependents']) ? count($results['dependents']) : 0,
            ],
        ];

        // Structure holder data (similar to AeroAmbulancia holder structure)
        if (isset($results['titular'])) {
            $universalAssistanceData['holder'] = [
                'data' => $results['titular'],
                'control_number' => $results['titular']['control_number'] ?? null,
                'voucher_id' => $results['titular']['voucher_response']['IdVoucher'] ?? null,
                'quotation_type' => $results['titular']['quotation_type'] ?? null,
                'status' => 'active',
            ];
        }

        // Structure dependents data (similar to AeroAmbulancia dependents structure)
        if (isset($results['dependents']) && ! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                $universalAssistanceData['dependents'][] = [
                    'data' => $dependent,
                    'status' => 'registered', // Not active until separate processing
                ];
            }
        }

        // Update the message with Universal Assistance data (exact same pattern as AeroAmbulancia)
        if ($messageId) {
            $message = Message::getById($messageId);
            $messageData = $message->message;
            $messageData['universalAssistanceData'] = $universalAssistanceData;
            $message->message = $messageData;
            $message->saveOrFail();
        }

        $order->metadata = array_merge(($order->metadata ?? []), ['universalAssistanceData' => $universalAssistanceData]);
        $order->saveOrFail();
    }
}
