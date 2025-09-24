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
        // Calculate total vouchers created (titular + dependents)
        $totalVouchers = 0;
        if (isset($results['titular'])) {
            $totalVouchers++;
        }
        if (isset($results['dependents'])) {
            $totalVouchers += count($results['dependents']);
        }

        // Collect all vouchers for consolidated tracking
        $allVouchers = [];

        if (isset($results['titular'])) {
            // Extract quotation details from titular result
            $titularQuoteData = $results['titular']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               $results['titular']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               [];

            $allVouchers[] = [
                'person_type' => 'titular',
                'control_number' => $results['titular']['control_number'] ?? null,
                'voucher_id' => $results['titular']['voucher_response']['IdVoucher'] ?? null,
                'quotation_type' => $results['titular']['quotation_type'] ?? null,
                'organization' => $results['titular']['organization'] ?? null,
                'convenio' => $results['titular']['convenio'] ?? null,
                'precio_emision' => $titularQuoteData['PrecioEmision'] ?? null,
                'moneda_lista' => $titularQuoteData['MonedaLista'] ?? null,
                'nombre_producto' => $titularQuoteData['NombreProducto'] ?? null,
                'producto' => $titularQuoteData['Producto'] ?? null,
                'status' => 'active',
                'created_at' => now()->toISOString(),
            ];
        }

        if (isset($results['dependents']) && ! empty($results['dependents'])) {
            foreach ($results['dependents'] as $index => $dependent) {
                // Extract quotation details from dependent result
                $dependentQuoteData = $dependent['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     $dependent['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     [];

                $allVouchers[] = [
                    'person_type' => 'dependent',
                    'dependent_index' => $index,
                    'control_number' => $dependent['control_number'] ?? null,
                    'voucher_id' => $dependent['voucher_response']['IdVoucher'] ?? null,
                    'quotation_type' => $dependent['quotation_type'] ?? null,
                    'organization' => $dependent['organization'] ?? null,
                    'convenio' => $dependent['convenio'] ?? null,
                    'precio_emision' => $dependentQuoteData['PrecioEmision'] ?? null,
                    'moneda_lista' => $dependentQuoteData['MonedaLista'] ?? null,
                    'nombre_producto' => $dependentQuoteData['NombreProducto'] ?? null,
                    'producto' => $dependentQuoteData['Producto'] ?? null,
                    'status' => 'active',
                    'created_at' => now()->toISOString(),
                ];
            }
        }

        // Prepare universalAssistanceData structure (updated for individual vouchers)
        $universalAssistanceData = [
            'processed_at' => now()->toISOString(),
            'workflow_type' => 'individual_voucher_per_person',
            'holder' => null,
            'dependents' => [],
            'vouchers' => $allVouchers, // Consolidated list of all vouchers
            'summary' => [
                'titular_processed' => isset($results['titular']),
                'dependents_processed' => isset($results['dependents']) ? count($results['dependents']) : 0,
                'total_vouchers_created' => $totalVouchers,
                'individual_vouchers' => true,
                'voucher_ids' => array_filter(array_column($allVouchers, 'voucher_id')),
                'control_numbers' => array_filter(array_column($allVouchers, 'control_number')),
                'productos_cotizados' => array_filter(array_column($allVouchers, 'nombre_producto')),
                'precios_emision' => array_filter(array_column($allVouchers, 'precio_emision')),
                'monedas_cotizacion' => array_unique(array_filter(array_column($allVouchers, 'moneda_lista'))),
            ],
        ];

        // Structure holder data (titular with individual voucher)
        if (isset($results['titular'])) {
            $titularQuoteData = $results['titular']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               $results['titular']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                               [];

            $universalAssistanceData['holder'] = [
                'data' => $results['titular'],
                'control_number' => $results['titular']['control_number'] ?? null,
                'voucher_id' => $results['titular']['voucher_response']['IdVoucher'] ?? null,
                'quotation_type' => $results['titular']['quotation_type'] ?? null,
                'organization' => $results['titular']['organization'] ?? null,
                'convenio' => $results['titular']['convenio'] ?? null,
                'quotation_details' => [
                    'precio_emision' => $titularQuoteData['PrecioEmision'] ?? null,
                    'moneda_lista' => $titularQuoteData['MonedaLista'] ?? null,
                    'nombre_producto' => $titularQuoteData['NombreProducto'] ?? null,
                    'producto' => $titularQuoteData['Producto'] ?? null,
                    'id_producto' => $titularQuoteData['IdProducto'] ?? null,
                    'precio_neto' => $titularQuoteData['PrecioNeto'] ?? null,
                ],
                'status' => 'active',
                'has_individual_voucher' => true,
            ];
        }

        // Structure dependents data (each with individual voucher)
        if (isset($results['dependents']) && ! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                $dependentQuoteData = $dependent['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     $dependent['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     [];

                $universalAssistanceData['dependents'][] = [
                    'data' => $dependent,
                    'control_number' => $dependent['control_number'] ?? null,
                    'voucher_id' => $dependent['voucher_response']['IdVoucher'] ?? null,
                    'quotation_type' => $dependent['quotation_type'] ?? null,
                    'organization' => $dependent['organization'] ?? null,
                    'convenio' => $dependent['convenio'] ?? null,
                    'quotation_details' => [
                        'precio_emision' => $dependentQuoteData['PrecioEmision'] ?? null,
                        'moneda_lista' => $dependentQuoteData['MonedaLista'] ?? null,
                        'nombre_producto' => $dependentQuoteData['NombreProducto'] ?? null,
                        'producto' => $dependentQuoteData['Producto'] ?? null,
                        'id_producto' => $dependentQuoteData['IdProducto'] ?? null,
                        'precio_neto' => $dependentQuoteData['PrecioNeto'] ?? null,
                    ],
                    'status' => 'active',
                    'has_individual_voucher' => true,
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

            if (!empty($titularQuoteData)) {
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
        if (isset($results['dependents']) && !empty($results['dependents'])) {
            foreach ($results['dependents'] as $index => $dependent) {
                $dependentQuoteData = $dependent['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     $dependent['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                     [];

                if (!empty($dependentQuoteData)) {
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
}
