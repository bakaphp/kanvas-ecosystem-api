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

                // Process insurance workflow with cart data from order metadata
                $results = $service->processInsuranceWorkflow($data['cart_data']);

                // Store results in eSim message and order metadata (same pattern as AeroAmbulancia)
                $this->storeUniversalAssistanceData($order, $data['message_id'], $results);

                return $results;
            },
            company: $order->company,
        );
    }

    /**
     * Get all required data for the activity (extracts insurance from eSim metadata)
     */
    protected function getActivityData(Order $order, array $params): array
    {
        // First try params (for direct workflow calls)
        $insuranceData = $params['insurance'] ?? [];

        // If not in params, extract from eSim metadata (workflow triggered by order creation)
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

        // Final fallback: direct metadata locations
        if (empty($insuranceData)) {
            $insuranceData = $order->metadata['universal_assistance']['insurance'] ?? $order->getMetadata('insurance') ?? [];
        }

        if (empty($insuranceData)) {
            throw new \Kanvas\Exceptions\ValidationException('Insurance data is required in order metadata');
        }

        if (! isset($insuranceData['titular'])) {
            throw new \Kanvas\Exceptions\ValidationException('Titular data is required in insurance metadata');
        }

        // Get eSim message ID from order (same way as AeroAmbulancia)
        $messageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);
        if (! $messageId) {
            throw new \Kanvas\Exceptions\ValidationException('eSim Message ID not found in order - required for Universal Assistance processing');
        }

        // Convert insurance data to cart format that the service expects
        $cartData = [
            'items' => []
        ];

        // Add titular to cart items
        if (isset($insuranceData['titular'])) {
            $cartData['items'][] = [
                'type' => 'titular',
                'data' => $insuranceData['titular']
            ];
        }

        // Add dependents to cart items
        if (isset($insuranceData['dependents']) && is_array($insuranceData['dependents'])) {
            foreach ($insuranceData['dependents'] as $dependent) {
                $cartData['items'][] = [
                    'type' => 'dependent',
                    'data' => $dependent
                ];
            }
        }

        return [
            'cart_data' => $cartData,
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
