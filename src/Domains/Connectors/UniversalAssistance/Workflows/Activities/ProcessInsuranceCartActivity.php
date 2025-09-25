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
    protected function storeUniversalAssistanceData(Order $order, int $messageId, array $results): void
    {
        // Convert results to arrays to prevent stdClass errors
        $results = $this->convertObjectsToArrays($results);

        // Prepare universalAssistanceData structure (frontend-compatible format)
        $universalAssistanceData = [
            'holder' => null,
            'dependents' => [],
            'quotation_details' => null,
        ];

        // Structure holder data (frontend-compatible format)
        if (isset($results['titular'])) {
            $titularQuoteData = $results['titular']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               $results['titular']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               [];

            $titularVoucherResponse = $results['titular']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp'] ??
                                     $results['titular']['voucher_response'] ?? [];

            $universalAssistanceData['holder'] = [
                'control_number' => $results['titular']['control_number'] ?? null,
                'convenio' => $this->extractConvenioFromVoucherResult($results['titular']),
                'data' => [
                    'dual_quotation_results' => $results['titular']['dual_quotation_results'] ?? null,
                    'selected' => $results['titular']['selected_quotation'] ?? null,
                ],
                'error_code' => $titularVoucherResponse['ErrorCode'] ?? null,
                'error_msg' => $titularVoucherResponse['ErrorMsg'] ?? null,
                'has_individual_voucher' => true,
                'nro_control_ext' => $titularVoucherResponse['NroControlExt'] ?? null,
                'nro_voucher' => $titularVoucherResponse['NroVoucher'] ?? null,
                'organization' => $results['titular']['organization'] ?? null,
                'price_validation' => $results['titular']['price_validation'] ?? null,
                'product_validation' => $results['titular']['product_validation'] ?? null,
            ];

            // Set quotation details at root level
            $universalAssistanceData['quotation_details'] = [
                'precio_emision' => $titularQuoteData['PrecioEmision'] ?? null,
                'quotation_type' => $results['titular']['quotation_type'] ?? null,
                'status' => 'active',
                'voucher_id' => $results['titular']['voucher_response']['IdVoucher'] ?? null,
                'voucher_success' => ($titularVoucherResponse['ErrorCode'] ?? null) === '00',
            ];
        }

        // Structure dependents data (frontend-compatible format)
        if (isset($results['dependents']) && ! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                $dependentQuoteData = $dependent['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     $dependent['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     [];

                $dependentVoucherResponse = $dependent['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp'] ??
                                           $dependent['voucher_response'] ?? [];

                $universalAssistanceData['dependents'][] = [
                    'control_number' => $dependent['control_number'] ?? null,
                    'convenio' => $this->extractConvenioFromVoucherResult($dependent),
                    'data' => [
                        'dual_quotation_results' => $dependent['dual_quotation_results'] ?? null,
                        'selected' => $dependent['selected_quotation'] ?? null,
                    ],
                    'error_code' => $dependentVoucherResponse['ErrorCode'] ?? null,
                    'error_msg' => $dependentVoucherResponse['ErrorMsg'] ?? null,
                    'has_individual_voucher' => true,
                    'nro_control_ext' => $dependentVoucherResponse['NroControlExt'] ?? null,
                    'nro_voucher' => $dependentVoucherResponse['NroVoucher'] ?? null,
                    'organization' => $dependent['organization'] ?? null,
                    'price_validation' => $dependent['price_validation'] ?? null,
                    'product_validation' => $dependent['product_validation'] ?? null,
                ];
            }
        }

        // Update the message with Universal Assistance data (smart merge with existing data)
        if ($messageId) {
            $message = Message::getById($messageId);
            $messageData = $message->message;

            // Smart merge with existing universalAssistanceData
            $existingData = $messageData['universalAssistanceData'] ?? [];
            $messageData['universalAssistanceData'] = $this->mergeUniversalAssistanceData($existingData, $universalAssistanceData);

            $message->message = $messageData;
            $message->saveOrFail();
        }

        // Smart merge with existing order metadata
        $existingOrderData = $order->metadata['universalAssistanceData'] ?? [];
        $mergedData = $this->mergeUniversalAssistanceData($existingOrderData, $universalAssistanceData);
        $order->metadata = array_merge(($order->metadata ?? []), ['universalAssistanceData' => $mergedData]);
        $order->saveOrFail();
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
        // The convenio is calculated and stored as 'convenio_used' in the workflow
        // Just extract it from the voucher result (same pattern as precio_emision)
        return $personData['convenio_used'] ?? null;
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
