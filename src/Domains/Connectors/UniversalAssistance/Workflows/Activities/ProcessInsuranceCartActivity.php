<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalAssistance\Services\InsuranceWorkflowService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Repositories\MessagesTypesRepository;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class ProcessInsuranceCartActivity extends KanvasActivity
{
    /**
     * Process insurance data from order
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

                // Process each eSIM separately to create individual vouchers
                $allResults = [];
                $allVoucherData = [];

                // Check if we have multiple eSIMs to process
                if (! empty($data['all_insurance_data'])) {
                    // Process each eSIM separately
                    foreach ($data['all_insurance_data'] as $index => $esimInsuranceData) {
                        // Create separate service instance for each eSIM with its specific message_id
                        $service = new InsuranceWorkflowService($app, $order, $esimInsuranceData['message_id'] ?? null);

                        // Process this specific eSIM's insurance workflow
                        $esimResults = $service->processInsuranceWorkflow($esimInsuranceData['insurance']);

                        // Store results with eSIM index for tracking
                        $allResults["esim_{$index}"] = $esimResults;

                        // Store results in eSim message and order metadata for this specific eSIM
                        $this->storeUniversalAssistanceData($esimResults, $esimInsuranceData['message_id'] ?? $data['message_id']);

                        // Collect voucher data for this eSIM
                        $allVoucherData["esim_{$index}"] = [
                            'esim_index' => $index,
                            'message_id' => $esimInsuranceData['message_id'] ?? null,
                            'voucher_data' => $this->extractVoucherDataFromResults($esimResults)
                        ];
                    }

                    // Use combined results
                    $results = $allResults;
                } else {
                    // Fallback to single eSIM processing (legacy support)
                    $service = new InsuranceWorkflowService($app, $order);
                    $results = $service->processInsuranceWorkflow($data['insurance_data']);

                    // Store results in eSim message and order metadata (same pattern as AeroAmbulancia)
                    $this->storeUniversalAssistanceData($results, $data['message_id']);

                    // For single eSIM, wrap in same structure for consistency
                    $allVoucherData["esim_0"] = [
                        'esim_index' => 0,
                        'message_id' => $data['message_id'],
                        'voucher_data' => $this->extractVoucherDataFromResults($results)
                    ];
                }

                // ADDITIONAL: Create separate messages for each eSIM with universal_assistance_data
                $this->createSeparateMessagesForEachESim($data, $results, $order, $app);

                // Return comprehensive results focusing on voucher data and SOAP inputs
                return [
                    'workflow_results' => $results,
                    'voucher_data' => $allVoucherData, // Now contains all eSIMs' voucher data
                    'original_insurance_data' => $data['insurance_data'],
                    'all_insurance_data' => $data['all_insurance_data'] ?? [], // Include all processed data
                    'message_id' => $data['message_id'],
                    'order_id' => $order->getId(),
                    'processing_summary' => [
                        'esims_processed' => count($allVoucherData),
                        'is_multi_esim' => count($allVoucherData) > 1,
                        'vouchers_created' => $this->countVouchersCreatedFromMultiResults($results),
                        'total_cost' => $this->calculateTotalCostFromMultiResults($results),
                    ]
                ];
            },
            company: $order->company,
        );
    }

    /**
     * Get all required data for the activity (supports both multi-eSIM and single eSIM structures)
     */
    protected function getActivityData(Order $order, array $params): array
    {
        $insuranceData = [];
        $allInsuranceData = []; // For collecting multiple eSIM insurance data with expanded quantities
        $messageIds = []; // For collecting all message IDs

        // Approach 1: Try params with Order class key (for single eSIM legacy structure)
        $orderKey = "Kanvas\\Souk\\Orders\\Models\\Order";
        if (isset($params[$orderKey]['metadata']['esims']) && is_array($params[$orderKey]['metadata']['esims'])) {
            foreach ($params[$orderKey]['metadata']['esims'] as $esim) {
                if (isset($esim['eSimDetails']['insurance'])) {
                    $quantity = (int) ($esim['quantity'] ?? 1);
                    $baseMessageId = $esim['message_id'] ?? null;

                    // Expand insurance data by quantity (each quantity needs separate insurance processing)
                    for ($i = 0; $i < $quantity; $i++) {
                        $expandedInsurance = $esim['eSimDetails']['insurance'];

                        // Calculate unique message_id for each expanded instance
                        $currentMessageId = $baseMessageId;
                        if ($quantity > 1 && $baseMessageId) {
                            // For multiple quantities, we need to handle message_id appropriately
                            // This might need adjustment based on how message_ids are generated for quantities
                            $currentMessageId = $baseMessageId + $i; // Simple increment, may need different logic
                        }

                        $allInsuranceData[] = [
                            'insurance' => $expandedInsurance,
                            'message_id' => $currentMessageId,
                            'esim_index' => count($allInsuranceData), // Track expanded index
                            'original_quantity' => $quantity,
                            'quantity_index' => $i
                        ];

                        if ($currentMessageId) {
                            $messageIds[] = $currentMessageId;
                        }

                        // Use first expanded insurance as primary
                        if (empty($insuranceData)) {
                            $insuranceData = $expandedInsurance;
                        }
                    }
                }
            }
        }

        // Approach 2: Extract from order metadata (multi-eSIM workflow pattern)
        if (empty($insuranceData)) {
            $orderMetadata = $order->metadata ?? [];

            // Look in esims metadata (created by eSim workflow)
            if (isset($orderMetadata['esims']) && is_array($orderMetadata['esims'])) {
                foreach ($orderMetadata['esims'] as $esim) {
                    if (isset($esim['eSimDetails']['insurance'])) {
                        $quantity = (int) ($esim['quantity'] ?? 1);
                        $baseMessageId = $esim['message_id'] ?? null;

                        // Expand insurance data by quantity
                        for ($i = 0; $i < $quantity; $i++) {
                            $expandedInsurance = $esim['eSimDetails']['insurance'];

                            $currentMessageId = $baseMessageId;
                            if ($quantity > 1 && $baseMessageId) {
                                $currentMessageId = $baseMessageId + $i;
                            }

                            $allInsuranceData[] = [
                                'insurance' => $expandedInsurance,
                                'message_id' => $currentMessageId,
                                'esim_index' => count($allInsuranceData),
                                'original_quantity' => $quantity,
                                'quantity_index' => $i
                            ];

                            if ($currentMessageId) {
                                $messageIds[] = $currentMessageId;
                            }

                            if (empty($insuranceData)) {
                                $insuranceData = $expandedInsurance;
                            }
                        }
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
            throw new ValidationException('Insurance data is required - not found in workflow params or order metadata');
        }

        // Convert any objects to arrays (in case data was JSON decoded as objects)
        $insuranceData = $this->convertObjectsToArrays($insuranceData);

        if (! isset($insuranceData['titular'])) {
            throw new ValidationException('Titular data is required in insurance data. Available keys: ' . implode(', ', array_keys($insuranceData)));
        }

        // Get primary message ID (fallback logic if no expanded data found)
        $primaryMessageId = null;

        if (! empty($messageIds)) {
            $primaryMessageId = $messageIds[0]; // Use first message ID
        } else {
            // Fallback to order custom field
            $primaryMessageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);

            // Last resort fallbacks
            if (! $primaryMessageId) {
                if (isset($params[$orderKey]['metadata']['esims']) && is_array($params[$orderKey]['metadata']['esims'])) {
                    foreach ($params[$orderKey]['metadata']['esims'] as $esim) {
                        if (isset($esim['message_id'])) {
                            $primaryMessageId = $esim['message_id'];
                            break;
                        }
                    }
                }
            }
        }

        if (! $primaryMessageId) {
            throw new \Kanvas\Exceptions\ValidationException('eSim Message ID not found in order - required for Universal Assistance processing');
        }

        // Return insurance data with multi-eSIM support and quantity expansion
        return [
            'insurance_data' => $insuranceData,
            'all_insurance_data' => $allInsuranceData, // Expanded by quantity with message_ids
            'message_id' => $primaryMessageId, // Primary message ID for backward compatibility
            'all_message_ids' => $messageIds, // All message IDs for expanded processing
            'is_multi_esim' => count($allInsuranceData) > 1,
            'total_expanded_count' => count($allInsuranceData) // Total after quantity expansion
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
        // Create a flat holder structure with only necessary fields
        $voucherRequestInput = $holder['voucher_result']['voucher_request_input'] ?? [];
        $solicitante = $voucherRequestInput['DatosSolicitante'] ?? [];

        $holderData = [
            // Extract key data we want to preserve from holder with múltiples fallbacks
            'id' => $holder['id'] ?? null,
            'firstName' => $holder['firstName']
                ?? $solicitante['NombreSolicitante']
                ?? $holder['firstname']
                ?? null,
            'lastName' => $holder['lastName']
                ?? $solicitante['ApellidoSolicitante']
                ?? $holder['lastname']
                ?? null,
            'birthDate' => $holder['birthDate']
                ?? $solicitante['FechaNacimientoSolicitante']
                ?? null,
            'documentNumber' => $holder['documentNumber']
                ?? $solicitante['NroDocumentoSolicitante']
                ?? $holder['idNumber']
                ?? null,
            'documentType' => $holder['documentType']
                ?? $solicitante['TipoDocumentoSolicitante']
                ?? $holder['idType']
                ?? null,
            'email' => $holder['email']
                ?? $solicitante['CorreoElectronicoSolicitante']
                ?? null,
            'telephone' => $holder['telephone']
                ?? $solicitante['TelefonoSolicitante']
                ?? null,
            'gender' => $holder['gender']
                ?? $solicitante['SexoSolicitante']
                ?? null,
        ];

        // Add the voucher fields directly at the top level of holder
        $holderData['error_code'] = $voucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? $voucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? $voucherResult['voucher_data']['voucher_response']['ErrorCode']
            ?? $voucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? $voucherResult['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            // Fallbacks for legacy structure
            ?? $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
            ?? $voucherResult['error_code']
            ?? '00';

        $holderData['error_msg'] = $voucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? $voucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? $voucherResult['voucher_data']['voucher_response']['ErrorMsg']
            ?? $voucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? $voucherResult['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            // Fallbacks for legacy structure
            ?? $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
            ?? $voucherResult['error_msg']
            ?? 'OK';

        $holderData['has_individual_voucher'] = true;
        $holderData['nro_control_ext'] = $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroControlExt']
            ?? $voucherResult['voucher_data']['control_number']
            ?? $holder['voucher_result']['voucher_data']['control_number']
            ?? $holder['selected_quotation']['quotation_data']['control_number']
            ?? $holder['dual_quotation_results']['cross_selling']['result']['quotation_data']['control_number']
            ?? $holder['dual_quotation_results']['inclusion']['result']['quotation_data']['control_number']
            ?? null;

        $holderData['nro_voucher'] = $holder['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $holder['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
            ?? $holder['voucher_result']['voucher_data']['voucher_response']['IdVoucher']
            ?? $holder['voucher_result']['voucher_data']['voucher_response']['NroVoucher']
            // Fallbacks for other structures
            ?? $holder['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $holder['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            // Fallbacks for legacy structure
            ?? $holder['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $holder['voucher_id']
            ?? $voucherResult['voucher_id']
            ?? $voucherResult['voucher_data']['voucher_id']
            ?? $holder['voucher_result']['voucher_id']
            ?? $holder['voucher_result']['voucher_data']['voucher_id']
            ?? $voucherResult['voucher_data']['nro_voucher']
            ?? $holder['voucher_result']['voucher_data']['nro_voucher']
            ?? $holder['selected_quotation']['quotation_data']['nro_voucher']
            ?? $holder['voucher_data']['nro_voucher']
            ?? $holder['voucher_data']['voucher_id']
            ?? $holder['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $holder['voucher_response']['DatosVoucherResp']['NroVoucher']
            ?? $holder['dual_quotation_results']['cross_selling']['result']['voucher_data']['nro_voucher']
            ?? $holder['dual_quotation_results']['cross_selling']['result']['voucher_data']['voucher_id']
            ?? $holder['dual_quotation_results']['cross_selling']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $holder['dual_quotation_results']['inclusion']['result']['voucher_data']['nro_voucher']
            ?? $holder['dual_quotation_results']['inclusion']['result']['voucher_data']['voucher_id']
            ?? $holder['dual_quotation_results']['inclusion']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $results['titular']['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $results['titular']['voucher_id']
            ?? null;

        $holderData['organization'] = $voucherResult['voucher_data']['organization']
            ?? $holder['voucher_result']['voucher_data']['organization']
            ?? $holder['selected_quotation']['quotation_data']['organization']
            ?? $holder['dual_quotation_results']['cross_selling']['result']['quotation_data']['organization']
            ?? $holder['dual_quotation_results']['inclusion']['result']['quotation_data']['organization']
            ?? null;
        // Now build the complete universalAssistanceData structure
        $universalAssistanceData = [
            'holder' => $holderData,
            'dependents' => array_map(function ($dependent) {
                // Create a new flat dependent structure with only necessary fields
                $dependentVoucherResult = $dependent['voucher_result'] ?? [];
                $dependentVoucherRequestInput = $dependentVoucherResult['voucher_request_input'] ?? [];
                $dependentSolicitante = $dependentVoucherRequestInput['DatosSolicitante'] ?? [];

                $dependentData = [
                    // Extract key data we want to preserve from dependent with multiple fallbacks (same pattern as holder)
                    'id' => $dependent['id'] ?? null,
                    'firstName' => $dependent['firstName']
                        ?? $dependentSolicitante['NombreSolicitante']
                        ?? $dependent['firstname']
                        ?? null,
                    'lastName' => $dependent['lastName']
                        ?? $dependentSolicitante['ApellidoSolicitante']
                        ?? $dependent['lastname']
                        ?? null,
                    'birthDate' => $dependent['birthDate']
                        ?? $dependentSolicitante['FechaNacimientoSolicitante']
                        ?? null,
                    'documentNumber' => $dependent['documentNumber']
                        ?? $dependentSolicitante['NroDocumentoSolicitante']
                        ?? $dependent['idNumber']
                        ?? null,
                    'documentType' => $dependent['documentType']
                        ?? $dependentSolicitante['TipoDocumentoSolicitante']
                        ?? $dependent['idType']
                        ?? null,
                    'email' => $dependent['email']
                        ?? $dependentSolicitante['CorreoElectronicoSolicitante']
                        ?? null,
                    'telephone' => $dependent['telephone']
                        ?? $dependentSolicitante['TelefonoSolicitante']
                        ?? null,
                    'gender' => $dependent['gender']
                        ?? $dependentSolicitante['SexoSolicitante']
                        ?? null,
                    'relationship' => $dependent['relationship'] ?? null,
                ];

                // Add the voucher fields directly at the top level of the dependent
                $dependentData['error_code'] = $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode']
                    ?? $dependentVoucherResult['error_code']
                    ?? '00';

                $dependentData['error_msg'] = $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg']
                    ?? $dependentVoucherResult['error_msg']
                    ?? 'OK';

                $dependentData['has_individual_voucher'] = true;
                $dependentData['nro_control_ext'] = $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroControlExt']
                    ?? $dependentVoucherResult['voucher_data']['control_number']
                    ?? $dependent['voucher_result']['voucher_data']['control_number']
                    ?? $dependent['selected_quotation']['quotation_data']['control_number']
                    ?? $dependent['dual_quotation_results']['cross_selling']['result']['quotation_data']['control_number']
                    ?? $dependent['dual_quotation_results']['inclusion']['result']['quotation_data']['control_number']
                    ?? null;

                $dependentData['nro_voucher'] = $dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
                    ?? $dependent['voucher_result']['voucher_data']['voucher_response']['IdVoucher']
                    ?? $dependent['voucher_result']['voucher_data']['voucher_response']['NroVoucher']
                    // Fallbacks for other structures
                    ?? $dependent['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    // Fallbacks for legacy structure
                    ?? $dependent['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependentVoucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['voucher_id']
                    ?? $dependentVoucherResult['voucher_id']
                    ?? $dependentVoucherResult['voucher_data']['voucher_id']
                    ?? $dependent['voucher_result']['voucher_id']
                    ?? $dependent['voucher_result']['voucher_data']['voucher_id']
                    ?? $dependentVoucherResult['voucher_data']['nro_voucher']
                    ?? $dependent['voucher_result']['voucher_data']['nro_voucher']
                    ?? $dependent['selected_quotation']['quotation_data']['nro_voucher']
                    ?? $dependent['voucher_data']['nro_voucher']
                    ?? $dependent['voucher_data']['voucher_id']
                    ?? $dependent['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['voucher_response']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['dual_quotation_results']['cross_selling']['result']['voucher_data']['nro_voucher']
                    ?? $dependent['dual_quotation_results']['cross_selling']['result']['voucher_data']['voucher_id']
                    ?? $dependent['dual_quotation_results']['cross_selling']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $dependent['dual_quotation_results']['inclusion']['result']['voucher_data']['nro_voucher']
                    ?? $dependent['dual_quotation_results']['inclusion']['result']['voucher_data']['voucher_id']
                    ?? $dependent['dual_quotation_results']['inclusion']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? null;

                $dependentData['organization'] = $dependentVoucherResult['voucher_data']['organization']
                    ?? $dependent['voucher_result']['voucher_data']['organization']
                    ?? $dependent['selected_quotation']['quotation_data']['organization']
                    ?? $dependent['dual_quotation_results']['cross_selling']['result']['quotation_data']['organization']
                    ?? $dependent['dual_quotation_results']['inclusion']['result']['quotation_data']['organization']
                    ?? null;

                return $dependentData;
            }, $dependents),
        ];

        // Get the message and update its message content with proper merge
        $message = Message::getById($messageId);
        $currentMessage = $message->message ?? [];

        // Ensure we have an array to work with
        if (! is_array($currentMessage)) {
            $currentMessage = [];
        }

        if (! isset($currentMessage['universalAssistanceData'])) {
            $currentMessage['universalAssistanceData'] = [
                'holder' => [],
                'dependents' => []
            ];
        }

        if (isset($universalAssistanceData['holder'])) {
            if (! empty($universalAssistanceData['holder']['nro_voucher'])) {
                $currentMessage['universalAssistanceData']['holder'] = $universalAssistanceData['holder'];
            } else {
                $existingVoucherId = $currentMessage['universalAssistanceData']['holder']['nro_voucher'] ?? null;
                $currentMessage['universalAssistanceData']['holder'] = $universalAssistanceData['holder'];
                if ($existingVoucherId && empty($currentMessage['universalAssistanceData']['holder']['nro_voucher'])) {
                    $currentMessage['universalAssistanceData']['holder']['nro_voucher'] = $existingVoucherId;
                }
            }
        }

        if (isset($universalAssistanceData['dependents'])) {
            $currentMessage['universalAssistanceData']['dependents'] = $universalAssistanceData['dependents'];
        }

        $message->message = $currentMessage;
        $message->saveOrFail();

        // Create a separate message with universal_assistance_data message type (ORIGINAL FUNCTIONALITY)
        $this->createUniversalAssistanceDataMessage($message, $universalAssistanceData);
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
            return '1-FOVL9FB'; // Default inclusion convenio
        } elseif ($quotationType === 'cross_selling') {
            return '1-FOVL9FG'; // Default cross_selling convenio
        }

        // Ultimate fallback
        return '1-FOVL9FB';
    }

    /**
     * Count total vouchers created in the results
     */
    protected function countVouchersCreated(array $results): int
    {
        $count = 0;

        // Count titular voucher with updated structure awareness
        if (! empty($results['titular'])) {
            // First check the correct structure: voucher_result.voucher_data.voucher_response.UAAltaVoucheMinResponse.DatosVoucherResp.NroVoucher
            $hasVoucher = ! empty($results['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['voucher_response']['IdVoucher']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['voucher_response']['NroVoucher']) ||
                         // Fallbacks for other structures
                         ! empty($results['titular']['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                         // Legacy structure fallbacks
                         ! empty($results['titular']['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                         ! empty($results['titular']['voucher_result']['voucher_id']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['voucher_id']) ||
                         ! empty($results['titular']['voucher_result']['voucher_data']['nro_voucher']) ||
                         ! empty($results['titular']['voucher_id']) ||
                         ! empty($results['titular']['voucher_data']['nro_voucher']) ||
                         ! empty($results['titular']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']);

            if ($hasVoucher) {
                $count++;
            }
        }

        // Count dependent vouchers with updated structure awareness
        if (! empty($results['dependents'])) {
            foreach ($results['dependents'] as $dependent) {
                // First check the correct structure: voucher_result.voucher_data.voucher_response.UAAltaVoucheMinResponse.DatosVoucherResp.NroVoucher
                $hasVoucher = ! empty($dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['voucher_response']['IdVoucher']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['voucher_response']['NroVoucher']) ||
                             // Fallbacks for other structures
                             ! empty($dependent['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                             // Legacy structure fallbacks
                             ! empty($dependent['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']) ||
                             ! empty($dependent['voucher_result']['voucher_id']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['voucher_id']) ||
                             ! empty($dependent['voucher_result']['voucher_data']['nro_voucher']) ||
                             ! empty($dependent['voucher_id']) ||
                             ! empty($dependent['voucher_data']['nro_voucher']) ||
                             ! empty($dependent['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']);

                if ($hasVoucher) {
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

        // Add titular cost
        if (! empty($results['titular']['voucher_result']['voucher_data']['quote_response'])) {
            $titularPrice = $this->extractPriceFromQuoteResponse($results['titular']['voucher_result']['voucher_data']['quote_response']);
            $totalCost += $titularPrice;
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

    /**
     * Extract voucher ID from data with multiple fallbacks
     * This helper ensures we get the voucher ID from any possible location
     */
    protected function extractVoucherId(array $data): ?string
    {
        // First try direct access to the voucher_response structure (actual voucher creation)
        $directVoucherId = $data['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
            ?? $data['voucher_response']['IdVoucher']
            ?? $data['voucher_response']['NroVoucher']
            // Then try quotation response structure
            ?? $data['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['result']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? null;

        if ($directVoucherId) {
            return $directVoucherId;
        }

        // Fallback to nested structure searches
        $voucherResult = $data['voucher_result'] ?? [];

        return $data['voucher_result']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['voucher_id']
            ?? $voucherResult['voucher_id']
            ?? $voucherResult['voucher_data']['voucher_id']
            ?? $data['voucher_result']['voucher_id']
            ?? $data['voucher_result']['voucher_data']['voucher_id']
            ?? $voucherResult['voucher_data']['nro_voucher']
            ?? $data['voucher_result']['voucher_data']['nro_voucher']
            ?? $data['selected_quotation']['quotation_data']['nro_voucher']
            ?? $data['voucher_data']['nro_voucher']
            ?? $data['voucher_data']['voucher_id']
            ?? $data['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['voucher_response']['DatosVoucherResp']['NroVoucher']
            ?? $data['dual_quotation_results']['cross_selling']['result']['voucher_data']['nro_voucher']
            ?? $data['dual_quotation_results']['cross_selling']['result']['voucher_data']['voucher_id']
            ?? $data['dual_quotation_results']['cross_selling']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $data['dual_quotation_results']['inclusion']['result']['voucher_data']['nro_voucher']
            ?? $data['dual_quotation_results']['inclusion']['result']['voucher_data']['voucher_id']
            ?? $data['dual_quotation_results']['inclusion']['result']['quotation_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? null;
    }

    /**
     * Create separate messages for each eSIM with universal_assistance_data message type
     * This is ADDITIONAL to the existing storeUniversalAssistanceData logic
     */
    protected function createSeparateMessagesForEachESim(array $data, array $results, Order $order, AppInterface $app): void
    {
        // Check if we have expanded eSIM data
        if (empty($data['all_insurance_data'])) {
            return;
        }

        // Get the original message for reference
        $originalMessage = Message::getById($data['message_id']);
        if (! $originalMessage) {
            return;
        }

        // Create a separate message for each expanded eSIM insurance
        foreach ($data['all_insurance_data'] as $index => $esimData) {
            $messageId = $esimData['message_id'] ?? null;
            if (! $messageId) {
                continue;
            }

            // Get the specific message for this eSIM
            $esimMessage = Message::getById($messageId);
            if (! $esimMessage) {
                continue;
            }

            // Prepare universal assistance data for this specific eSIM
            $universalAssistanceData = [
                'esim_index' => $esimData['esim_index'],
                'quantity_index' => $esimData['quantity_index'],
                'original_quantity' => $esimData['original_quantity'],
                'insurance_data' => $esimData['insurance'],
                'results' => $results, // Full results for processing
                'order_id' => $order->getId(),
                'processing_timestamp' => time()
            ];

            // Create a separate message with universal_assistance_data message type
            $this->createUniversalAssistanceDataMessage($esimMessage, $universalAssistanceData);
        }
    }

    /**
     * Create a separate message with universal_assistance_data message type
     */
    protected function createUniversalAssistanceDataMessage(Message $originalMessage, array $universalAssistanceData): void
    {
        // Get the universal_assistance_data message type
        $messageType = MessagesTypesRepository::getByVerb(
            'universal_assistance_data',
            $originalMessage->app
        );

        // Create message input DTO
        $messageInput = new MessageInput(
            app: $originalMessage->app,
            company: $originalMessage->company,
            user: $originalMessage->user,
            type: $messageType,
            message: $universalAssistanceData,
            parent_id: $originalMessage->getId(), // Set original message as parent
            is_public: 1, // Keep it public
            slug: null
        );

        // Create the message
        $createMessageAction = new CreateMessageAction($messageInput);
        $createMessageAction->runWorkflow = false; // Prevent triggering workflows for this internal message
        $newMessage = $createMessageAction->execute();
    }

    /**
     * Extract voucher data from insurance workflow results for a single eSIM
     */
    protected function extractVoucherDataFromResults(array $results): array
    {
        return [
            'holder' => [
                // The correct structure is: $results['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                'voucher_id' => $results['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $results['titular']['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
                    ?? $results['titular']['voucher_result']['voucher_data']['voucher_response']['IdVoucher']
                    ?? $results['titular']['voucher_result']['voucher_data']['voucher_response']['NroVoucher']
                    // Fallback paths for other possible structures
                    ?? $results['titular']['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $results['titular']['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                    ?? $this->extractVoucherId($results['titular']['voucher_result']['voucher_data'] ?? [])
                    ?? null,
                'voucher_request_input' => $results['titular']['voucher_result']['voucher_request_input'] ?? null,
                'soap_response' => $results['titular']['voucher_result']['voucher_data'] ?? null,
            ],
            'dependents' => array_map(function ($dependent) {
                return [
                    // Same structure for dependents
                    'voucher_id' => $dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                        ?? $dependent['voucher_result']['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
                        ?? $dependent['voucher_result']['voucher_data']['voucher_response']['IdVoucher']
                        ?? $dependent['voucher_result']['voucher_data']['voucher_response']['NroVoucher']
                        // Fallback paths for other possible structures
                        ?? $dependent['voucher_result']['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                        ?? $dependent['voucher_result']['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
                        ?? $this->extractVoucherId($dependent['voucher_result']['voucher_data'] ?? [])
                        ?? null,
                    'voucher_request_input' => $dependent['voucher_result']['voucher_request_input'] ?? null,
                    'soap_response' => $dependent['voucher_result']['voucher_data'] ?? null,
                ];
            }, $results['dependents'] ?? [])
        ];
    }

    /**
     * Count vouchers created from multi-eSIM results
     */
    protected function countVouchersCreatedFromMultiResults(array $multiResults): int
    {
        $count = 0;

        foreach ($multiResults as $esimKey => $results) {
            if (str_starts_with($esimKey, 'esim_')) {
                $count += $this->countVouchersCreated($results);
            } else {
                // Single eSIM fallback
                $count += $this->countVouchersCreated($multiResults);
                break;
            }
        }

        return $count;
    }

    /**
     * Calculate total cost from multi-eSIM results
     */
    protected function calculateTotalCostFromMultiResults(array $multiResults): float
    {
        $totalCost = 0.0;

        foreach ($multiResults as $esimKey => $results) {
            if (str_starts_with($esimKey, 'esim_')) {
                $totalCost += $this->calculateTotalCost($results);
            } else {
                // Single eSIM fallback
                $totalCost += $this->calculateTotalCost($multiResults);
                break;
            }
        }

        return $totalCost;
    }
}
