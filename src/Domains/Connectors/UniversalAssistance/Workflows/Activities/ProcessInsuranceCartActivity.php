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
                $this->storeUniversalAssistanceData($results, $data['message_id']);

                // Return comprehensive results focusing on voucher data and SOAP inputs
                return [
                    'workflow_results' => $results,
                    'voucher_data' => [
                        'holder' => [
                            'voucher_id' => $results['holder']['voucher_result']['voucher_id'] ?? null,
                            'voucher_request_input' => $results['holder']['voucher_result']['voucher_request_input'] ?? null,
                            'soap_response' => $results['holder']['voucher_result']['voucher_response'] ?? null,
                        ],
                        'dependents' => array_map(function ($dependent) {
                            return [
                                'voucher_id' => $dependent['voucher_result']['voucher_id'] ?? null,
                                'voucher_request_input' => $dependent['voucher_result']['voucher_request_input'] ?? null,
                                'soap_response' => $dependent['voucher_result']['voucher_response'] ?? null,
                            ];
                        }, $results['dependents'] ?? [])
                    ],
                    'original_insurance_data' => $data['insurance_data'],
                    'message_id' => $data['message_id'],
                    'order_id' => $order->getId(),
                    'processing_summary' => [
                        'holder_processed' => ! empty($results['holder']),
                        'dependents_processed' => count($results['dependents'] ?? []),
                        'vouchers_created' => $this->countVouchersCreated($results),
                        'total_cost' => $this->calculateTotalCost($results),
                    ]
                ];
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
            $insuranceData = $orderMetadata['universalAssistanceData']['insurance'] ??
                           $orderMetadata['universal_assistance']['insurance'] ?? // Fallback for old data
                           $order->getMetadata('insurance') ??
                           [];
        }

        // Validate that we have insurance data
        if (empty($insuranceData)) {
            throw new \Kanvas\Exceptions\ValidationException('Insurance data is required - not found in workflow params or order metadata');
        }

        // Convert any objects to arrays (in case data was JSON decoded as objects)
        $insuranceData = $this->convertObjectsToArrays($insuranceData);

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
     * Convert objects (stdClass) to arrays recursively
     */
    protected function convertObjectsToArrays($data)
    {
        if (is_object($data)) {
            $data = (array) $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convertObjectsToArrays($value);
            }
        }

        return $data;
    }

    /**
     * Store Universal Assistance data in both message and order (same pattern as AeroAmbulancia)
     */
    protected function storeUniversalAssistanceData(array $results, int $messageId): void
    {
        // Extract individual insurance quote results for titular and dependents
        $holder = $results['titular'] ?? null;
        $dependents = $results['dependents'] ?? [];

        if (! $holder) {
            return;
        }

        // Get voucher result from holder to extract convenio and pricing data
        $voucherResult = $holder['voucher_result'] ?? [];
        $convenio = $this->extractConvenioFromVoucherResult($voucherResult);
        $precioEmision = $voucherResult['precio_emision'] ?? null;

                // Build the universalAssistanceData structure for frontend consumption
        $universalAssistanceData = [
            // Keep the original processed data structure with additional fields inside holder
            'holder' => array_merge($holder, [
                // Add the additional structure from the image inside holder
                'error_code' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode'] 
                    ?? $voucherResult['error_code'] 
                    ?? null,
                'error_msg' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg'] 
                    ?? $voucherResult['error_msg'] 
                    ?? null,
                'has_individual_voucher' => true,
                'nro_control_ext' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroControlExt'] 
                    ?? $voucherResult['nro_control_ext'] 
                    ?? $voucherResult['control_number'] 
                    ?? $holder['control_number'] 
                    ?? null,
                'nro_voucher' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] 
                    ?? $voucherResult['voucher_id'] 
                    ?? null,
                'organization' => $voucherResult['organization'] ?? null,
                'price_validation' => null,
                'product_validation' => null
            ]),
            'dependents' => array_map(function ($dependent) {
                $dependentVoucherResult = $dependent['voucher_result'] ?? [];
                return array_merge($dependent, [
                    // Add the additional structure from the image inside each dependent
                    'error_code' => $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode'] 
                        ?? $dependentVoucherResult['error_code'] 
                        ?? null,
                    'error_msg' => $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg'] 
                        ?? $dependentVoucherResult['error_msg'] 
                        ?? null,
                    'has_individual_voucher' => true,
                    'nro_control_ext' => $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroControlExt'] 
                        ?? $dependentVoucherResult['nro_control_ext'] 
                        ?? $dependentVoucherResult['control_number'] 
                        ?? $dependent['control_number'] 
                        ?? null,
                    'nro_voucher' => $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] 
                        ?? $dependentVoucherResult['voucher_id'] 
                        ?? null,
                    'organization' => $dependentVoucherResult['organization'] ?? null,
                    'price_validation' => null,
                    'product_validation' => null
                ]);
            }, $dependents),
            'quotation_details' => [
                'precio_emision' => $precioEmision,
                'quotation_type' => $holder['quotation_type'] ?? null,
                'status' => 'active',
                'voucher_id' => $voucherResult['voucher_id'] ?? null,
                'voucher_success' => ! empty($voucherResult['voucher_id'])
            ]
        ];

        // Get the message and update its message content with proper merge
        $message = Message::getById($messageId);
        $currentMessage = $message->message ?? [];

        // Ensure we have an array to work with
        if (! is_array($currentMessage)) {
            $currentMessage = [];
        }

        // Merge the universalAssistanceData without overwriting existing data
        $currentMessage = array_merge($currentMessage, [
            'universalAssistanceData' => $universalAssistanceData
        ]);

        $message->message = $currentMessage;
        $message->saveOrFail();
    }

    /**
     * Get validation summary for all vouchers created
     * This method can be used to validate that all products and prices match expectations
     */
    protected function getValidationSummary(array $results): array
    {
        $validation = [
            'total_validations' => 0,
            'product_matches' => 0,
            'price_matches' => 0,
            'validation_details' => []
        ];

        // Validate titular
        if (isset($results['titular'])) {
            $titularQuoteData = $results['titular']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               $results['titular']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               [];

            if (! empty($titularQuoteData)) {
                $validation['total_validations']++;
                $validation['validation_details']['titular'] = [
                    'nombre_producto_cotizado' => $titularQuoteData['NombreProducto'] ?? null,
                    'precio_emision' => $titularQuoteData['PrecioEmision'] ?? null,
                    'moneda_lista' => $titularQuoteData['MonedaLista'] ?? null,
                    'control_number' => $results['titular']['control_number'] ?? null,
                ];
            }
        }

        // Validate dependents
        if (isset($results['dependents']) && ! empty($results['dependents'])) {
            foreach ($results['dependents'] as $index => $dependent) {
                $dependentQuoteData = $dependent['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     $dependent['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     [];

                if (! empty($dependentQuoteData)) {
                    $validation['total_validations']++;
                    $validation['validation_details']["dependent_$index"] = [
                        'nombre_producto_cotizado' => $dependentQuoteData['NombreProducto'] ?? null,
                        'precio_emision' => $dependentQuoteData['PrecioEmision'] ?? null,
                        'moneda_lista' => $dependentQuoteData['MonedaLista'] ?? null,
                        'control_number' => $dependent['control_number'] ?? null,
                    ];
                }
            }
        }

        return $validation;
    }

    /**
     * Smart merge of Universal Assistance data to avoid overwriting existing information
     * Arrays are merged, objects are recursively merged
     */
    protected function mergeUniversalAssistanceData(array $existing, array $new): array
    {
        $result = $existing;

        foreach ($new as $key => $value) {
            if (! isset($result[$key])) {
                // If key doesn't exist in existing, just add it
                $result[$key] = $value;
            } elseif (is_array($value) && is_array($result[$key])) {
                // Both are arrays
                if ($this->isAssociativeArray($value) && $this->isAssociativeArray($result[$key])) {
                    // Both are associative arrays (objects), merge recursively
                    $result[$key] = $this->mergeUniversalAssistanceData($result[$key], $value);
                } else {
                    // At least one is indexed array, append new values
                    $result[$key] = array_merge($result[$key], $value);
                }
            } else {
                // Scalar value or type mismatch, overwrite with new value
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Extract convenio from voucher result (simplified - like precio_emision)
     */
    protected function extractConvenioFromVoucherResult(array $personData): ?string
    {
        // The convenio is calculated and stored as 'convenio_used' in the workflow result
        // Check multiple possible locations in the result structure

        // Level 1: Direct convenio_used from workflow result
        if (! empty($personData['convenio_used'])) {
            return $personData['convenio_used'];
        }

        // Level 2: From voucher_result.convenio_used (voucher creation result)
        if (! empty($personData['voucher_result']['convenio_used'])) {
            return $personData['voucher_result']['convenio_used'];
        }

        // Level 3: From selected quotation convenio
        if (! empty($personData['selected_quotation']['convenio'])) {
            return $personData['selected_quotation']['convenio'];
        }

        // Level 4: From quotation_type fallback (like workflow does)
        $quotationType = $personData['quotation_type'] ?? $personData['quotation_type_used'] ?? 'inclusion';
        if ($quotationType === 'inclusion') {
            return '1-8JMLB4N'; // Default inclusion convenio
        } elseif ($quotationType === 'cross_selling') {
            return '1-DEY2E2H'; // Default cross_selling convenio
        }

        // Ultimate fallback
        return '1-8JMLB4N';
    }

    /**
     * Count total vouchers created in the results
     */
    protected function countVouchersCreated(array $results): int
    {
        $count = 0;

        // Count holder voucher
        if (! empty($results['holder']['voucher_result']['voucher_id'])) {
            $count++;
        }

        // Count dependent vouchers
        if (! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                if (! empty($dependent['voucher_result']['voucher_id'])) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * Calculate total cost from all vouchers
     */
    protected function calculateTotalCost(array $results): float
    {
        $totalCost = 0.0;

        // Add holder cost
        if (! empty($results['holder']['voucher_result']['voucher_data']['quote_response'])) {
            $holderPrice = $this->extractPriceFromQuoteResponse($results['holder']['voucher_result']['voucher_data']['quote_response']);
            $totalCost += $holderPrice;
        }

        // Add dependent costs
        if (! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                if (! empty($dependent['voucher_result']['voucher_data']['quote_response'])) {
                    $dependentPrice = $this->extractPriceFromQuoteResponse($dependent['voucher_result']['voucher_data']['quote_response']);
                    $totalCost += $dependentPrice;
                }
            }
        }

        return $totalCost;
    }

    /**
     * Extract price from Universal Assistance quote response
     */
    protected function extractPriceFromQuoteResponse(array $quoteResponse): float
    {
        // Universal Assistance structure
        if (isset($quoteResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'])) {
            $cotizadorData = $quoteResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'];

            // Handle both single object and array of objects
            if (is_array($cotizadorData) && isset($cotizadorData[0])) {
                $cotizadorData = $cotizadorData[0]; // Take first item if array
            }

            return (float) ($cotizadorData['PrecioEmision'] ?? $cotizadorData['PrecioBruto'] ?? 0);
        }

        return 0.0;
    }

    /**
     * Check if array is associative (object-like) vs indexed (list-like)
     */
    protected function isAssociativeArray(array $array): bool
    {
        if (empty($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }
}
