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
     * Process insurance data from cart and create single quotation based on plan type
     */
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::UNIVERSAL_ASSISTANCE,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                // Get cart data from params
                $cartData = $params['cart_data'] ?? [];

                if (empty($cartData)) {
                    throw new \Kanvas\Exceptions\ValidationException('Cart data is required for insurance processing');
                }

                // Validate cart data structure
                if (! isset($cartData['items'])) {
                    throw new \Kanvas\Exceptions\ValidationException('Cart data must contain items array');
                }

                // Get eSim message ID from order (same way as AeroAmbulancia)
                $messageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);
                if (! $messageId) {
                    throw new \Kanvas\Exceptions\ValidationException('eSim Message ID not found in order - required for Universal Assistance processing');
                }

                // Log the processing start

                try {
                    // Create service (message ID will be obtained from order automatically)
                    $service = new InsuranceWorkflowService($app, $order);

                    // Process insurance workflow
                    $results = $service->processInsuranceWorkflow($cartData);

                    // Store results in eSim message and order metadata (same pattern as AeroAmbulancia)
                    $this->storeUniversalAssistanceData($order, $messageId, $results);

                    // Log success

                    return $results;
                } catch (\Exception $e) {
                    // Log error and re-throw
                    throw $e;
                }
            }
        );
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
        if (isset($results['dependents']) && !empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                $universalAssistanceData['dependents'][] = [
                    'data' => $dependent,
                    'status' => 'registered', // Not active until separate processing
                ];
            }
        }

        // Update the message with universalAssistanceData (same pattern as aeroAmbulanciaData)
        if ($messageId) {
            $message = Message::getById($messageId);
            $messageData = $message->message;
            $messageData['universalAssistanceData'] = $universalAssistanceData;
            $message->message = $messageData;
            $message->saveOrFail();
        }

        // Update order metadata as well (same pattern as aeroAmbulanciaData)
        $order->metadata = array_merge(($order->metadata ?? []), [
            'universalAssistanceData' => $universalAssistanceData
        ]);
        $order->saveOrFail();
    }
}
