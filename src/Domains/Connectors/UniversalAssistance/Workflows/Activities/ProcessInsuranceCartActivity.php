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
     * Process insurance data from order (enhanced original pattern for multiple eSIMs)
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

                // Get all insurance data
                $allInsuranceData = $this->getAllInsuranceDataFromOrder($order, $params);

                // Create service
                $service = new InsuranceWorkflowService($app, $order);

                // Process all insurances and collect results
                $allResults = [];
                $totalVouchersCreated = 0;
                $totalCost = 0.0;

                foreach ($allInsuranceData as $index => $insuranceDataItem) {
                    // Process insurance workflow with insurance data directly
                    $results = $service->processInsuranceWorkflow($insuranceDataItem['insurance_data']);

                    // Store results in eSim message (same pattern as original)
                    $this->storeUniversalAssistanceData($results, $insuranceDataItem['message_id']);

                    // Build individual insurance results (like original voucher_data structure)
                    $vouchersCreated = $this->countVouchersCreated($results);
                    $cost = $this->calculateTotalCost($results);

                    $totalVouchersCreated += $vouchersCreated;
                    $totalCost += $cost;

                    $allResults[] = [
                        'esim_sequence' => $insuranceDataItem['esim_sequence'],
                        'message_id' => $insuranceDataItem['message_id'],
                        'workflow_results' => $results,
                        'voucher_data' => [
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
                        ],
                        'original_insurance_data' => $insuranceDataItem['insurance_data'],
                        'processing_summary' => [
                            'holder_processed' => ! empty($results['titular']),
                            'dependents_processed' => count($results['dependents'] ?? []),
                            'vouchers_created' => $vouchersCreated,
                            'total_cost' => $cost,
                        ]
                    ];
                }

                // Return comprehensive results (enhanced original format)
                return [
                    'order_id' => $order->getId(),
                    'total_insurances_processed' => count($allResults),
                    'workflow_results' => count($allResults) === 1 ? $allResults[0]['workflow_results'] : $allResults, // Single result for backward compatibility
                    'voucher_data' => count($allResults) === 1 ? $allResults[0]['voucher_data'] : array_column($allResults, 'voucher_data'), // Single voucher_data for backward compatibility
                    'original_insurance_data' => count($allResults) === 1 ? $allResults[0]['original_insurance_data'] : array_column($allResults, 'original_insurance_data'),
                    'processing_summary' => [
                        'holder_processed' => $totalVouchersCreated > 0,
                        'dependents_processed' => array_sum(array_column(array_column($allResults, 'processing_summary'), 'dependents_processed')),
                        'vouchers_created' => $totalVouchersCreated,
                        'total_cost' => $totalCost,
                    ],
                    'insurances' => $allResults, // Full details for multiple eSIMs
                ];
            },
            company: $order->company,
        );
    }

    /**
     * Get all insurance data from order (enhanced version of original getActivityData for multiple eSIMs)
     */
    protected function getAllInsuranceDataFromOrder(Order $order, array $params): array
    {
        $allInsuranceData = [];

        // Approach 1: Try multiple eSIMs from workflow params (new functionality)
        if (isset($params['esims']) && is_array($params['esims'])) {
            foreach ($params['esims'] as $index => $esim) {
                if (isset($esim['eSimDetails']['insurance']) && isset($esim['message_id'])) {
                    $insuranceData = $this->convertObjectsToArrays($esim['eSimDetails']['insurance']);

                    // Validate titular data exists
                    if (isset($insuranceData['titular'])) {
                        $quantity = $esim['data']['quantity'] ?? ($esim['quantity'] ?? 1);
                        $messageIds = $esim['message_ids'] ?? null;

                        // Create entry for each quantity unit
                        for ($i = 0; $i < $quantity; $i++) {
                            $unitMessageId = (isset($messageIds) && isset($messageIds[$i]))
                                ? (int) $messageIds[$i]
                                : (int) $esim['message_id'];

                            $suffix = $quantity > 1 ? "-" . ($i + 1) : "";

                            $allInsuranceData[] = [
                                'insurance_data' => $insuranceData,
                                'message_id' => $unitMessageId,
                                'esim_sequence' => ($esim['esim_sequence'] ?? ($index + 1)) . $suffix
                            ];
                        }
                    }
                }
            }

            if (! empty($allInsuranceData)) {
                return $allInsuranceData;
            }
        }

        $orderKey = "Kanvas\\Souk\\Orders\\Models\\Order";
        if (isset($params[$orderKey]['metadata']['esims']) && is_array($params[$orderKey]['metadata']['esims'])) {
            foreach ($params[$orderKey]['metadata']['esims'] as $index => $esim) {
                if (isset($esim['eSimDetails']['insurance'])) {
                    $insuranceData = $this->convertObjectsToArrays($esim['eSimDetails']['insurance']);

                    // Validate titular data exists
                    if (isset($insuranceData['titular'])) {
                        $quantity = $esim['data']['quantity'] ?? ($esim['total_quantity'] ?? 1);
                        $messageIds = $esim['message_ids'] ?? null;

                        // Get message_id from multiple possible locations
                        $messageId = $esim['message_id']
                            ?? $params[$orderKey]['message_id']
                            ?? $params[$orderKey]['metadata']['message_id']
                            ?? $params['message_id']
                            ?? null;

                        if (! $messageId) {
                            // Skip this eSIM if no message_id found
                            continue;
                        }

                        // Create entry for each quantity unit
                        for ($i = 0; $i < $quantity; $i++) {
                            $unitMessageId = (isset($messageIds) && isset($messageIds[$i]))
                                ? (int) $messageIds[$i]
                                : (int) $messageId;

                            $suffix = $quantity > 1 ? "-" . ($i + 1) : "";

                            $allInsuranceData[] = [
                                'insurance_data' => $insuranceData,
                                'message_id' => $unitMessageId,
                                'esim_sequence' => ($esim['esim_sequence'] ?? ($index + 1)) . $suffix
                            ];
                        }
                    }
                }
            }

            if (! empty($allInsuranceData)) {
                return $allInsuranceData;
            }
        }

        // Approach 2: Single insurance from workflow params (original functionality)
        $insuranceData = [];
        $messageId = null;

        // Try direct params first
        if (isset($params['titular'])) {
            $insuranceData = $params;
            $messageId = $params['message_id'] ?? null;
        } elseif (isset($params['insurance'])) {
            $insuranceData = $params['insurance'];
            $messageId = $params['message_id'] ?? null;
        }
        // Try to extract from Order in params as fallback
        elseif (isset($params[$orderKey]['metadata']['esims'][0]['eSimDetails']['insurance'])) {
            $firstEsim = $params[$orderKey]['metadata']['esims'][0];
            $insuranceData = $firstEsim['eSimDetails']['insurance'];

            // Get message_id from multiple possible locations
            $messageId = $firstEsim['message_id']                    // First try: individual eSIM message_id
                ?? $params[$orderKey]['message_id']             // Second try: Order level message_id
                ?? $params[$orderKey]['metadata']['message_id'] // Third try: Order metadata message_id
                ?? null;
        }

        // Convert objects to arrays
        if (! empty($insuranceData)) {
            $insuranceData = $this->convertObjectsToArrays($insuranceData);

            if (! isset($insuranceData['titular'])) {
                throw new ValidationException('Titular data is required in insurance data. Available keys: ' . implode(', ', array_keys($insuranceData)));
            }

            // Get message IDs from params or extracted data
            $messageIds = $params['message_ids'] ?? null;
            if (! $messageId && ! $messageIds) {
                throw new ValidationException('eSim Message ID not found in params - each eSIM must have its specific message_id for Universal Assistance processing');
            }

            $quantity = $params['data']['quantity'] ?? ($params['quantity'] ?? 1);

            // Create entry for each quantity unit
            for ($i = 0; $i < $quantity; $i++) {
                $unitMessageId = (is_array($messageIds) && isset($messageIds[$i]))
                    ? (int) $messageIds[$i]
                    : (int) $messageId;

                $suffix = $quantity > 1 ? "-" . ($i + 1) : "";

                $allInsuranceData[] = [
                    'insurance_data' => $insuranceData,
                    'message_id' => $unitMessageId,
                    'esim_sequence' => ($params['esim_sequence'] ?? 1) . $suffix
                ];
            }

            return $allInsuranceData;
        }

        // If no insurance data found, throw exception
        throw new ValidationException('Insurance data is required - not found in workflow params');
    }

    /**
     * Get all required data for the activity (try both workflow params and order metadata)
     * @deprecated Use getAllInsuranceDataFromOrder instead
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
            throw new ValidationException('Insurance data is required - not found in workflow params or order metadata');
        }

        // Convert any objects to arrays (in case data was JSON decoded as objects)
        $insuranceData = $this->convertObjectsToArrays($insuranceData);

        if (! isset($insuranceData['titular'])) {
            throw new ValidationException('Titular data is required in insurance data. Available keys: ' . implode(', ', array_keys($insuranceData)));
        }

        // Get eSim message ID from order (same way as AeroAmbulancia)
        $messageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);
        if (! $messageId) {
            throw new ValidationException('eSim Message ID not found in order - required for Universal Assistance processing');
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

        // Create a separate message with universal_assistance_data message type
        $this->createUniversalAssistanceDataMessage($message, $universalAssistanceData);
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
}
