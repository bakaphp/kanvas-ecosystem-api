<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\AeroAmbulancia\Services\AeroAmbulanciaSubscriptionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateAeroAmbulanciaB2BSubscriptionActivity extends KanvasActivity
{
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        // Check if the source is B2B from order metadata
        $source = $order->getMetadata('source');
        if (! is_string($source) || strtoupper($source) !== 'B2B') {
            return [];
        }

        $subscriptionVariant = $order->allItems()->first()->variant;

        // Check if the product is from the Dominican Republic
        $productCountry = $subscriptionVariant->product->getAttributeBySlug('countries-code')?->value ?? 
                         $subscriptionVariant->product->getAttributeBySlug('destination')?->value ?? '';
        if (! is_string($productCountry) || strtoupper($productCountry) !== 'DO') {
            return [];
        }

        // Proceed with B2B integration
        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::AERO_AMBULANCIA,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($params) {
                sleep(30);
                $data = $this->getB2BActivityData($order, $params);

                // Skip execution if no valid aeroAmbulancia plans found
                if (isset($data['skip_execution']) && $data['skip_execution']) {
                    return [];
                }

                $subscriptionService = new AeroAmbulanciaSubscriptionService($app, $order);
                $results = [];

                // Process each order item that contains aeroAmbulancia plans
                foreach ($data['order_items_with_plans'] as $orderItemData) {
                    $orderItem = $orderItemData['order_item'];
                    $aeroPlans = $orderItemData['aero_plans'];

                    // Process each aeroAmbulancia plan within the order item
                    foreach ($aeroPlans as $planData) {
                        $beneficiaries = $this->formatB2BBeneficiaries($planData);
                        
                        // Only create subscription if beneficiaries data is valid
                        if (! empty($beneficiaries)) {
                            $result = $subscriptionService->createNewSubscription(
                                $data['people'],
                                [
                                    'beneficiaries' => $beneficiaries,
                                    'order_item_id' => $orderItem->id,
                                    'plan_label' => $planData['label'] ?? null,
                                ]
                            );
                            $results[] = $result;
                        }
                    }
                }

                return $results;
            },
            company: $order->company,
        );
    }

    /**
     * Get all required data for the B2B activity
     */
    protected function getB2BActivityData(Order $order, array $params): array
    {
        $people = $order->people;
        if (! $people instanceof People) {
            throw new ValidationException('Order must have a valid people record');
        }

        $orderItemsWithPlans = [];

        // Process each order item to find aeroAmbulancia plans
        foreach ($order->allItems()->get() as $orderItem) {
            $itemMetadata = $orderItem->metadata ?? [];
            
            if (isset($itemMetadata['eSimDetails']) && is_array($itemMetadata['eSimDetails'])) {
                $aeroPlans = array_filter($itemMetadata['eSimDetails'], function ($detail) {
                    // Check if aeroAmbulance exists and has valid titular data
                    return isset($detail['aeroAmbulance']['titular']) && 
                           is_array($detail['aeroAmbulance']['titular']);
                });

                if (! empty($aeroPlans)) {
                    $orderItemsWithPlans[] = [
                        'order_item' => $orderItem,
                        'aero_plans' => $aeroPlans,
                    ];
                }
            }
        }

        // If no aeroAmbulancia plans found, skip execution gracefully
        if (empty($orderItemsWithPlans)) {
            return [
                'people' => $people,
                'order_items_with_plans' => [],
                'skip_execution' => true,
            ];
        }

        return [
            'people' => $people,
            'order_items_with_plans' => $orderItemsWithPlans,
        ];
    }

    /**
     * Format aeroAmbulancia plan data into beneficiaries format
     */
    protected function formatB2BBeneficiaries(array $planData): array
    {
        $aeroData = $planData['aeroAmbulance'] ?? [];
        
        // Return empty array if no titular data is present (optional plan)
        if (! isset($aeroData['titular']) || ! is_array($aeroData['titular'])) {
            return [];
        }

        $beneficiaries = [
            'holder' => $aeroData['titular'],
        ];

        // Add dependents if they exist
        if (isset($aeroData['dependents']) && is_array($aeroData['dependents'])) {
            $beneficiaries['dependents'] = $aeroData['dependents'];
        }

        return $beneficiaries;
    }
}
