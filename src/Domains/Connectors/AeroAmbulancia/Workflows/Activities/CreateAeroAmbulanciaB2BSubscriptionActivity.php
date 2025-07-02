<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\AeroAmbulancia\Services\AeroAmbulanciaSubscriptionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class CreateAeroAmbulanciaB2BSubscriptionActivity extends KanvasActivity
{
    public function execute(Order $order, AppInterface $app, array $params): array
    {
        $subscriptionVariant = $order->allItems()->first()->variant;
        $mainAppCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $order->company);

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
            company: $mainAppCompany,
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

        $titular = $aeroData['titular'];

        // Get the ambulance variant ID from the titular's plan - this will be used for all beneficiaries
        $ambulanceVariantId = $titular['plan']['id'] ?? null;
        if (! $ambulanceVariantId) {
            throw new ValidationException('Missing plan ID in titular data');
        }

        // Transform titular data to expected format
        $holder = $this->transformPersonData($titular, true, $ambulanceVariantId);

        $beneficiaries = [
            'holder' => $holder,
        ];

        // Add dependents if they exist
        if (isset($aeroData['dependents']) && is_array($aeroData['dependents'])) {
            $dependents = [];
            foreach ($aeroData['dependents'] as $dependent) {
                // Pass the titular's ambulanceVariantId to dependents since they share the same plan
                $transformedDependent = $this->transformPersonData($dependent, false, $ambulanceVariantId);

                $relationship = $dependent['relationship'] ?? 'Other';
                $transformedDependent['holderRelationship'] = (int) $relationship;

                $dependents[] = $transformedDependent;
            }
            $beneficiaries['dependents'] = $dependents;
        }

        return $beneficiaries;
    }

    /**
     * Transform person data from eSimDetails format to AeroAmbulancia format
     */
    protected function transformPersonData(array $personData, bool $isHolder, string $ambulanceVariantId): array
    {
        $transformed = [
            'documentType' => $personData['idType'] ?? 'passport', // 'passport' or 'id'
            'documentNumber' => $personData['idNumber'] ?? '',
            'firstname' => $personData['firstname'] ?? '',
            'lastname' => $personData['lastname'] ?? '',
            'gender' => strtoupper($personData['sex'] ?? 'M'), // 'M' or 'F'
            'birthDate' => $this->formatDateForService($personData['dob'] ?? ''),
            'activationDate' => $this->formatDateForService($personData['activationDate'] ?? date('Y-m-d')),
            'phoneNumber' => $this->cleanPhoneNumber($personData['phone'] ?? ''),
            'preferredLanguage' => $personData['language'] ?? 'es',
            'ambulanceVariantId' => $ambulanceVariantId, // Required for all beneficiaries
        ];

        // Calculate expiration date if we have activation date and plan duration
        // For dependents, use the titular's plan duration
        $planDuration = null;
        if ($isHolder && isset($personData['plan']['duration'])) {
            $planDuration = (int) $personData['plan']['duration'];
        } else {
            // For dependents, we need to get the duration from the titular's plan
            // Since we don't have direct access here, we'll set a default or let the service handle it
            // The service will get the duration from the ambulanceVariantId
        }

        if ($planDuration && isset($transformed['activationDate'])) {
            $activationDate = \Carbon\Carbon::createFromFormat('d-m-Y', $transformed['activationDate']);
            $expirationDate = $activationDate->copy()->addDays($planDuration);
            $transformed['expirationDate'] = $expirationDate->format('d-m-Y');
        }

        return $transformed;
    }

    /**
     * Format date from various formats to d-m-Y format expected by service
     */
    protected function formatDateForService(string $date): string
    {
        if (empty($date)) {
            return date('d-m-Y');
        }

        try {
            // Try different date formats
            $formats = ['Y-m-d', 'd-m-Y', 'm/d/Y', 'Y/m/d'];

            foreach ($formats as $format) {
                try {
                    $carbonDate = \Carbon\Carbon::createFromFormat($format, $date);

                    return $carbonDate->format('d-m-Y');
                } catch (\Exception $e) {
                    continue;
                }
            }

            // If no format works, try parsing with Carbon's automatic detection
            $carbonDate = \Carbon\Carbon::parse($date);

            return $carbonDate->format('d-m-Y');
        } catch (\Exception $e) {
            // Return current date if all parsing fails
            return date('d-m-Y');
        }
    }

    /**
     * Clean and format phone number
     */
    protected function cleanPhoneNumber(string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        // Remove all non-digit characters
        $cleanPhone = preg_replace('/\D/', '', $phone);

        // Ensure it's 10 digits (add padding if needed)
        if (strlen($cleanPhone) < 10) {
            $cleanPhone = str_pad($cleanPhone, 10, '0', STR_PAD_LEFT);
        } elseif (strlen($cleanPhone) > 10) {
            // Take the last 10 digits
            $cleanPhone = substr($cleanPhone, -10);
        }

        return $cleanPhone;
    }
}
