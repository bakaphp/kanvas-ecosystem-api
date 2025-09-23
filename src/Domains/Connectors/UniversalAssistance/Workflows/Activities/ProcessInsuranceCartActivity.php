<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\UniversalAssistance\Services\InsuranceWorkflowService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Social\Messages\Models\Message;

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
                error_log("UniversalAssistance ProcessInsuranceCartActivity: Starting processing for order #{$order->id}, eSim message #{$messageId}");

                try {
                    // Create service (message ID will be obtained from order automatically)
                    $service = new InsuranceWorkflowService($app, $order);

                    // Process insurance workflow
                    $results = $service->processInsuranceWorkflow($cartData);

                    // Store results in eSim message metadata (same level as AeroAmbulancia)
                    $this->storeResultsInESimMessage($messageId, $results);

                    // Log success
                    error_log("UniversalAssistance ProcessInsuranceCartActivity: Successfully processed order #{$order->id}, stored in message #{$messageId}");

                    return $results;
                } catch (\Exception $e) {
                    // Log error and re-throw
                    error_log("UniversalAssistance ProcessInsuranceCartActivity: Error processing order #{$order->id}: " . $e->getMessage());
                    throw $e;
                }
            }
        );
    }

    /**
     * Store insurance processing results in eSim message metadata (same level as AeroAmbulancia)
     */
    protected function storeResultsInESimMessage(int $messageId, array $results): void
    {
        $message = Message::getById($messageId);
        $messageData = $message->message;

        // Store in message.message (same structure as AeroAmbulancia)
        $messageData['universal_assistance'] = [
            'processed_at' => now()->toISOString(),
            'workflow_type' => 'single_voucher_per_titular',
            'results' => $results,
            'summary' => [
                'titular_processed' => isset($results['titular']),
                'dependents_stored' => isset($results['dependents']) ? count($results['dependents']) : 0,
                'total_vouchers_created' => isset($results['titular']) ? 1 : 0, // Only titular gets voucher
                'total_dependents_in_metadata' => isset($results['dependents']) ? count($results['dependents']) : 0,
            ],
        ];

        // Add control number for easy reference
        if (isset($results['titular']['control_number'])) {
            $messageData['universal_assistance']['titular_control_number'] = $results['titular']['control_number'];
        }

        // Add plan type information
        if (isset($results['titular']['quotation_type'])) {
            $messageData['universal_assistance']['plan_type'] = $results['titular']['quotation_type'];
        }

        // Add voucher query information if available
        if (isset($results['titular']['voucher_query'])) {
            $messageData['universal_assistance']['titular_voucher_info'] = $results['titular']['voucher_query'];
        }

        // Save the updated message data (same as AeroAmbulancia)
        $message->message = $messageData;
        $message->saveOrFail();

        // Log metadata storage
        error_log("UniversalAssistance ProcessInsuranceCartActivity: Metadata stored in eSim message #{$messageId}");
    }
}
