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

                // Process each eSIM separately to create individual vouchers OR grouped vouchers by plan
                $allResults = [];
                $allVoucherData = [];

                // Check if we have multiple eSIMs to process
                if (! empty($data['all_insurance_data'])) {
                    // Process each eSIM separately
                    foreach ($data['all_insurance_data'] as $index => $esimInsuranceData) {
                        // Create separate service instance for each eSIM with its specific message_id
                        $service = new InsuranceWorkflowService($app, $order, $esimInsuranceData['message_id'] ?? null);

                        // NEW LOGIC: Group people by plan/convenio within this eSIM's insurance data
                        $esimResults = $this->processeSIMWithPlanGrouping($service, $esimInsuranceData['insurance'], $index);

                        // Store results with eSIM index for tracking
                        $allResults["esim_{$index}"] = $esimResults;

                        // Store results in eSim message and order metadata - handle grouped vouchers
                        $this->storeUniversalAssistanceDataWithGroupSupport($esimResults, $esimInsuranceData['message_id'] ?? $data['message_id'], $data);

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
                }

                // Store results in eSim message and order metadata (same pattern as AeroAmbulancia)
                $this->storeUniversalAssistanceData($results, $data['message_id']);

                // Return comprehensive results focusing on voucher data and SOAP inputs
                return [
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
                    'original_insurance_data' => $data['insurance_data'],
                    'message_id' => $data['message_id'],
                    'order_id' => $order->getId(),
                    'processing_summary' => [
                        'holder_processed' => ! empty($results['titular']),
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
     * Transform webhook data format to internal insurance format
     * Converts the webhook structure to the format expected by InsuranceWorkflowService
     */
    protected function transformWebhookDataFormat(array $insuranceData): array
    {
        // If data already has the expected format (titular key exists), return as is
        if (isset($insuranceData['titular'])) {
            return $insuranceData;
        }

        // Transform field names from webhook format to internal format if necessary
        if (isset($insuranceData['titular'])) {
            // Transform titular data
            $insuranceData['titular'] = $this->transformPersonDataFromWebhook($insuranceData['titular']);
        }

        if (isset($insuranceData['dependents']) && is_array($insuranceData['dependents'])) {
            // Transform dependents data
            foreach ($insuranceData['dependents'] as $index => $dependent) {
                $insuranceData['dependents'][$index] = $this->transformPersonDataFromWebhook($dependent);
            }
        }

        return $insuranceData;
    }

    /**
     * Transform person data from webhook format to internal format
     * Maps webhook field names to internal field names
     */
    protected function transformPersonDataFromWebhook(array $personData): array
    {
        // Map webhook field names to internal field names
        $fieldMap = [
            'dob' => 'birthDate',
            'sex' => 'gender',
            'firstname' => 'firstName',
            'lastname' => 'lastName',
            'phone' => 'telephone',
            'idType' => 'documentType',
            'idNumber' => 'documentNumber',
        ];

        $transformedData = $personData;

        // Apply field mappings
        foreach ($fieldMap as $webhookField => $internalField) {
            if (isset($personData[$webhookField]) && ! isset($transformedData[$internalField])) {
                $transformedData[$internalField] = $personData[$webhookField];
            }
        }

        // Transform gender format (m/f to M/F)
        if (isset($transformedData['gender'])) {
            $transformedData['gender'] = strtoupper($transformedData['gender']);
        }
        if (isset($transformedData['sex'])) {
            $transformedData['gender'] = strtoupper($transformedData['sex']);
        }

        // Transform date format to MM/DD/YY for Universal Assistance API
        if (isset($transformedData['birthDate'])) {
            $transformedData['birthDate'] = $this->convertDateToUniversalAssistanceFormat($transformedData['birthDate']);
        }
        if (isset($transformedData['dob'])) {
            $transformedData['birthDate'] = $this->convertDateToUniversalAssistanceFormat($transformedData['dob']);
        }

        // Ensure we have both originCountryCode and destinationCountryCode
        if (! isset($transformedData['originCountryCode']) && isset($personData['originCountryCode'])) {
            $transformedData['originCountryCode'] = $personData['originCountryCode'];
        }
        if (! isset($transformedData['destinationCountryCode']) && isset($personData['destinationCountryCode'])) {
            $transformedData['destinationCountryCode'] = $personData['destinationCountryCode'];
        }

        return $transformedData;
    }

    /**
     * Convert date to Universal Assistance format (MM/DD/YYYY)
     * Uses Carbon to handle date parsing and formatting
     */
    protected function convertDateToUniversalAssistanceFormat(string $dateString): string
    {
        try {
            // Use Carbon to parse the date - it handles most formats automatically
            $date = \Carbon\Carbon::parse($dateString);

            // Convert to MM/DD/YYYY format
            return $date->format('m/d/Y');
        } catch (\Exception $e) {
            // Return original string as fallback if parsing fails
            return $dateString;
        }
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
                'processing_timestamp' => time(),
                'grouping_info' => $this->extractGroupingInfo($results) // Add grouping information
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

    /**
     * Process a single eSIM with plan grouping logic
     * Groups titular and dependents by same plan/convenio to create fewer vouchers
     */
    protected function processeSIMWithPlanGrouping(InsuranceWorkflowService $service, array $insuranceData, int $esimIndex): array
    {
        // Convert any objects to arrays to prevent stdClass errors
        $insuranceData = $this->convertObjectsToArrays($insuranceData);

        // Collect all people (titular + dependents) with their plan keys
        $allPeople = [];
        $planGroups = [];

        // Add titular
        if (isset($insuranceData['titular'])) {
            $titularPlanKey = $service->generatePlanGroupKey($insuranceData['titular']);
            $allPeople[] = [
                'data' => $insuranceData['titular'],
                'type' => 'titular',
                'plan_key' => $titularPlanKey,
                'person_id' => 'titular'
            ];

            // Initialize plan group
            if (! isset($planGroups[$titularPlanKey])) {
                $planGroups[$titularPlanKey] = [];
            }
            $planGroups[$titularPlanKey][] = $insuranceData['titular'];
        }

        // Add dependents
        if (isset($insuranceData['dependents']) && ! empty($insuranceData['dependents'])) {
            foreach ($insuranceData['dependents'] as $index => $dependent) {
                $dependentPlanKey = $service->generatePlanGroupKey($dependent);
                $allPeople[] = [
                    'data' => $dependent,
                    'type' => 'dependent',
                    'plan_key' => $dependentPlanKey,
                    'person_id' => "dependent_{$index}"
                ];

                // Add to plan group
                if (! isset($planGroups[$dependentPlanKey])) {
                    $planGroups[$dependentPlanKey] = [];
                }
                $planGroups[$dependentPlanKey][] = $dependent;
            }
        }

        // Process each plan group separately
        $groupResults = [];
        $groupIndex = 0;

        foreach ($planGroups as $planKey => $groupPeople) {
            if (count($groupPeople) > 1) {
                // Multiple people with same plan = create group voucher
                $groupResult = $service->processGroupedInsuranceWorkflow($groupPeople, $planKey);
                $groupResults["group_{$groupIndex}"] = [
                    'type' => 'grouped_voucher',
                    'plan_key' => $planKey,
                    'group_size' => count($groupPeople),
                    'people_in_group' => $this->extractPeopleIdentifiers($groupPeople),
                    'result' => $groupResult
                ];
            } else {
                // Single person = process individually (existing logic)
                $person = $groupPeople[0];
                $personType = $this->findPersonType($person, $insuranceData);

                if ($personType === 'titular') {
                    $individualResult = $service->processTitular($person);
                    $groupResults["group_{$groupIndex}"] = [
                        'type' => 'individual_titular',
                        'plan_key' => $planKey,
                        'group_size' => 1,
                        'people_in_group' => ['titular'],
                        'result' => ['titular' => $individualResult]
                    ];
                } else {
                    // For dependents, we need titular's country data
                    $titularOriginCountryCode = $insuranceData['titular']['originCountryCode'] ?? 'AR';
                    $titularDestinationCountryCode = $insuranceData['titular']['destinationCountryCode'] ??
                                                   $insuranceData['titular']['destinyCountryCode'] ?? 'DO';

                    $individualResult = $service->processDependent($person, $titularOriginCountryCode, $titularDestinationCountryCode);
                    $groupResults["group_{$groupIndex}"] = [
                        'type' => 'individual_dependent',
                        'plan_key' => $planKey,
                        'group_size' => 1,
                        'people_in_group' => [$this->findPersonIdentifier($person, $insuranceData)],
                        'result' => ['dependents' => [$individualResult]]
                    ];
                }
            }
            $groupIndex++;
        }

        // Convert group results back to the expected format for compatibility
        return $this->convertGroupResultsToExpectedFormat($groupResults, $insuranceData);
    }

    /**
     * Extract identifiers for people in a group
     */
    protected function extractPeopleIdentifiers(array $groupPeople): array
    {
        $identifiers = [];
        foreach ($groupPeople as $index => $person) {
            $firstName = $person['firstName'] ?? $person['firstname'] ?? 'Unknown';
            $lastName = $person['lastName'] ?? $person['lastname'] ?? 'Person';
            $identifiers[] = "{$firstName} {$lastName}";
        }
        return $identifiers;
    }

    /**
     * Find person type (titular or dependent) within insurance data
     */
    protected function findPersonType(array $person, array $insuranceData): string
    {
        // Compare by document number or email to identify if it's titular
        $personDoc = $person['documentNumber'] ?? $person['idNumber'] ?? '';
        $personEmail = $person['email'] ?? '';

        $titularDoc = $insuranceData['titular']['documentNumber'] ?? $insuranceData['titular']['idNumber'] ?? '';
        $titularEmail = $insuranceData['titular']['email'] ?? '';

        if (($personDoc && $personDoc === $titularDoc) || ($personEmail && $personEmail === $titularEmail)) {
            return 'titular';
        }

        return 'dependent';
    }

    /**
     * Find specific person identifier within insurance data
     */
    protected function findPersonIdentifier(array $person, array $insuranceData): string
    {
        $personType = $this->findPersonType($person, $insuranceData);

        if ($personType === 'titular') {
            return 'titular';
        }

        // For dependents, find index
        if (isset($insuranceData['dependents'])) {
            foreach ($insuranceData['dependents'] as $index => $dependent) {
                $dependentDoc = $dependent['documentNumber'] ?? $dependent['idNumber'] ?? '';
                $personDoc = $person['documentNumber'] ?? $person['idNumber'] ?? '';

                if ($dependentDoc && $personDoc && $dependentDoc === $personDoc) {
                    return "dependent_{$index}";
                }
            }
        }

        return 'unknown_person';
    }

    /**
     * Convert group results back to the expected format for compatibility
     */
    protected function convertGroupResultsToExpectedFormat(array $groupResults, array $originalInsuranceData): array
    {
        $result = [
            'titular' => null,
            'dependents' => [],
            'grouping_metadata' => [
                'groups_created' => count($groupResults),
                'grouping_applied' => true,
                'original_people_count' => 1 + count($originalInsuranceData['dependents'] ?? [])
            ]
        ];

        // Extract titular and dependents from group results
        foreach ($groupResults as $groupKey => $group) {
            if ($group['type'] === 'individual_titular' || $group['type'] === 'grouped_voucher') {
                // Check if titular is in this group
                if (in_array('titular', $group['people_in_group']) ||
                    $group['type'] === 'grouped_voucher') {

                    if ($group['type'] === 'grouped_voucher') {
                        // For grouped vouchers, create a special result structure
                        $result['titular'] = [
                            'voucher_result' => $group['result']['group_voucher_result'],
                            'group_metadata' => [
                                'is_grouped' => true,
                                'group_size' => $group['group_size'],
                                'plan_key' => $group['plan_key'],
                                'people_in_group' => $group['people_in_group']
                            ]
                        ];
                    } else {
                        $result['titular'] = $group['result']['titular'];
                    }
                }
            }

            if ($group['type'] === 'individual_dependent') {
                // Add individual dependents
                $result['dependents'] = array_merge($result['dependents'], $group['result']['dependents']);
            }
        }

        return $result;
    }

    /**
     * Store Universal Assistance data with support for grouped vouchers
     * When vouchers are grouped, all people in the group share the same voucher number
     */
    protected function storeUniversalAssistanceDataWithGroupSupport(array $results, int $messageId, array $allData): void
    {
        // Check if we have grouping metadata (indicates grouped voucher processing)
        if (isset($results['grouping_metadata']['grouping_applied']) && $results['grouping_metadata']['grouping_applied']) {
            // Handle grouped vouchers - need to store same voucher number for all group members
            $this->storeGroupedVoucherData($results, $messageId, $allData);
        } else {
            // Standard individual voucher storage
            $this->storeUniversalAssistanceData($results, $messageId);
        }
    }

    /**
     * Store grouped voucher data - handle grouping within the same eSIM message
     * All people from the same eSIM share the same message_id
     */
    protected function storeGroupedVoucherData(array $results, int $messageId, array $allData): void
    {
        // For grouped vouchers, we need to modify the results structure to properly
        // represent the grouping within the same message (same eSIM)
        $modifiedResults = $this->adjustResultsForGroupedVouchers($results);

        // Store using the standard method with the modified results
        $this->storeUniversalAssistanceData($modifiedResults, $messageId);
    }

    /**
     * Adjust results structure to properly represent grouped vouchers
     * This ensures that when multiple people share a voucher, it's properly reflected in the message
     */
    protected function adjustResultsForGroupedVouchers(array $results): array
    {
        // If titular has group metadata, it means there's a group voucher
        if (isset($results['titular']['group_metadata']['is_grouped']) && $results['titular']['group_metadata']['is_grouped']) {
            $groupMetadata = $results['titular']['group_metadata'];
            $groupVoucherResult = $results['titular']['voucher_result'] ?? [];
            $groupVoucherNumber = $this->extractVoucherNumberFromGroupResult($groupVoucherResult);

            // Mark the titular as having a group voucher
            $results['titular']['voucher_result']['is_group_voucher'] = true;
            $results['titular']['voucher_result']['group_size'] = $groupMetadata['group_size'] ?? 1;
            $results['titular']['voucher_result']['group_people'] = $groupMetadata['people_in_group'] ?? [];
            $results['titular']['voucher_result']['shared_voucher_number'] = $groupVoucherNumber;

            // For dependents in the same group, mark them as sharing the group voucher
            if (! empty($results['dependents'])) {
                foreach ($results['dependents'] as &$dependent) {
                    // Check if this dependent is part of the group by comparing plan information
                    $dependentPlanKey = $this->generateDependentPlanKey($dependent);
                    $titularPlanKey = $groupMetadata['plan_key'] ?? '';

                    if ($dependentPlanKey === $titularPlanKey) {
                        // This dependent is part of the group
                        $dependent['voucher_result']['is_group_voucher'] = true;
                        $dependent['voucher_result']['shares_voucher_with_titular'] = true;
                        $dependent['voucher_result']['shared_voucher_number'] = $groupVoucherNumber;
                        $dependent['voucher_result']['group_size'] = $groupMetadata['group_size'] ?? 1;
                    }
                }
            }
        }

        return $results;
    }

    /**
     * Generate plan key for a dependent to check if they're in the same group
     */
    protected function generateDependentPlanKey(array $dependent): string
    {
        $planName = $dependent['plan']['name'] ?? 'unknown';
        $originCountryCode = $dependent['originCountryCode'] ?? 'unknown';
        $destinationCountryCode = $dependent['destinationCountryCode'] ?? $dependent['destinyCountryCode'] ?? 'unknown';

        return implode('|', [
            $planName,
            strtoupper($originCountryCode),
            strtoupper($destinationCountryCode)
        ]);
    }

    /**
     * Extract voucher number from group voucher result
     */
    protected function extractVoucherNumberFromGroupResult(array $groupVoucherResult): ?string
    {
        // Try multiple paths to find the voucher number
        return $groupVoucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $groupVoucherResult['voucher_data']['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['IdVoucher']
            ?? $groupVoucherResult['voucher_data']['voucher_response']['IdVoucher']
            ?? $groupVoucherResult['voucher_data']['voucher_response']['NroVoucher']
            ?? $groupVoucherResult['voucher_data']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $groupVoucherResult['voucher_data']['response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher']
            ?? $this->extractVoucherId($groupVoucherResult['voucher_data'] ?? [])
            ?? null;
    }

    /**
     * Find all message IDs for people in the same group
     */
    protected function findGroupMessageIds(array $groupMetadata, array $allData): array
    {
        $messageIds = [];
        $peopleInGroup = $groupMetadata['people_in_group'] ?? [];

        // Search through all insurance data to find matching people and their message IDs
        if (isset($allData['all_insurance_data'])) {
            foreach ($allData['all_insurance_data'] as $esimData) {
                $insuranceData = $esimData['insurance'] ?? [];

                // Check if titular is in the group
                if (isset($insuranceData['titular']) && in_array('titular', $peopleInGroup)) {
                    $messageIds[] = $esimData['message_id'];
                }

                // Check dependents
                if (isset($insuranceData['dependents'])) {
                    foreach ($insuranceData['dependents'] as $index => $dependent) {
                        $dependentName = ($dependent['firstName'] ?? $dependent['firstname'] ?? 'Unknown') . ' ' .
                                       ($dependent['lastName'] ?? $dependent['lastname'] ?? 'Person');

                        if (in_array($dependentName, $peopleInGroup)) {
                            $messageIds[] = $esimData['message_id'];
                        }
                    }
                }
            }
        }

        return array_unique(array_filter($messageIds));
    }

    /**
     * Store voucher data for a single person with group voucher number
     */
    protected function storeSinglePersonVoucherData(string $voucherNumber, array $voucherResult, array $groupMetadata, int $messageId): void
    {
        try {
            $message = Message::getById($messageId);
            if (!$message) {
                return;
            }

            $currentMessage = $message->message ?? [];
            if (! is_array($currentMessage)) {
                $currentMessage = [];
            }

            // Ensure universal assistance data structure exists
            if (! isset($currentMessage['universalAssistanceData'])) {
                $currentMessage['universalAssistanceData'] = [
                    'holder' => [],
                    'dependents' => []
                ];
            }

            // Create basic voucher data with group information
            $voucherData = [
                'nro_voucher' => $voucherNumber,
                'error_code' => '00',
                'error_msg' => 'OK',
                'has_individual_voucher' => false, // This is a group voucher
                'is_group_voucher' => true,
                'group_size' => $groupMetadata['group_size'] ?? 1,
                'plan_key' => $groupMetadata['plan_key'] ?? '',
                'group_people' => $groupMetadata['people_in_group'] ?? []
            ];

            // Store in holder section (assuming this message represents someone in the group)
            $currentMessage['universalAssistanceData']['holder'] = array_merge(
                $currentMessage['universalAssistanceData']['holder'] ?? [],
                $voucherData
            );

            $message->message = $currentMessage;
            $message->saveOrFail();

        } catch (\Exception $e) {
        }
    }

    /**
     * Extract grouping information from results for message storage
     */
    protected function extractGroupingInfo(array $results): array
    {
        $groupingInfo = [
            'has_groups' => false,
            'groups' => [],
            'total_groups' => 0
        ];

        // Check if results have grouping metadata
        if (isset($results['grouping_metadata']['grouping_applied']) && $results['grouping_metadata']['grouping_applied']) {
            $groupingInfo['has_groups'] = true;
            $groupingInfo['total_groups'] = $results['grouping_metadata']['groups_created'] ?? 0;
        }

        // Extract group voucher numbers and metadata
        if (isset($results['titular']['group_metadata']['is_grouped']) && $results['titular']['group_metadata']['is_grouped']) {
            $groupVoucherResult = $results['titular']['voucher_result'] ?? [];
            $voucherNumber = $this->extractVoucherNumberFromGroupResult($groupVoucherResult);

            $groupingInfo['groups'][] = [
                'voucher_number' => $voucherNumber,
                'group_size' => $results['titular']['group_metadata']['group_size'] ?? 1,
                'plan_key' => $results['titular']['group_metadata']['plan_key'] ?? '',
                'people_in_group' => $results['titular']['group_metadata']['people_in_group'] ?? []
            ];
        }

        return $groupingInfo;
    }
}
