<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class InsuranceWorkflowService
{
    protected Client $client;

    protected ?int $messageId = null;

    public function __construct(
        protected AppInterface $app,
        protected Order $order,
        ?int $messageId = null
    ) {
        $this->client = new Client($app, $order->company);

        // Get eSim message ID from order if not provided (same as AeroAmbulancia)
        $this->messageId = $messageId ?? $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);
    }

    /**
     * Process insurance data directly (no cart wrapper needed)
     */
    public function processInsuranceWorkflow(array $insuranceData): array
    {
        $results = [];

        if (empty($insuranceData)) {
            throw new ValidationException('No insurance data found');
        }

        // Convert any objects to arrays to prevent stdClass errors
        $insuranceData = $this->convertObjectsToArrays($insuranceData);

        // Extract variant type from eSIM data if available
        $esimVariantType = $this->extractVariantTypeFromESimData($insuranceData);

        // Extract titular's country information to use for all family members
        $titularOriginCountryCode = null;
        $titularDestinationCountryCode = null;

        // Process titular (main applicant)
        if (isset($insuranceData['titular'])) {
            // Extract country codes from titular data
            $titularOriginCountryCode = $insuranceData['titular']['originCountryCode'] ?? 'AR';
            $titularDestinationCountryCode = $insuranceData['titular']['destinationCountryCode'] ??
                                           $insuranceData['titular']['destinyCountryCode'] ?? 'DO';

            // Add variant information to titular data
            $titularDataWithVariant = $insuranceData['titular'];
            if ($esimVariantType) {
                $titularDataWithVariant['variantType'] = $esimVariantType;
            }

            $results['titular'] = $this->processTitular($titularDataWithVariant);
        } else {
            throw new ValidationException('Titular data not found in insurance data');
        }

        // Process dependents using titular's country information
        if (isset($insuranceData['dependents']) && ! empty($insuranceData['dependents'])) {
            $results['dependents'] = [];
            foreach ($insuranceData['dependents'] as $dependent) {
                // Add variant information to dependent data
                $dependentDataWithVariant = $dependent;
                if ($esimVariantType) {
                    $dependentDataWithVariant['variantType'] = $esimVariantType;
                }

                $results['dependents'][] = $this->processDependent($dependentDataWithVariant, $titularOriginCountryCode, $titularDestinationCountryCode);
            }
        }

        return $results;
    }

    /**
     * Extract variant type from the global eSIM data context
     */
    private function extractVariantTypeFromESimData(array $data): ?string
    {
        // Check if we're processing from a full order/eSIM context
        if (isset($data['variant_info']['attributes']['Variant Type'])) {
            $variantType = strtolower(trim($data['variant_info']['attributes']['Variant Type']));
            if (in_array($variantType, ['basic', 'unlimited'])) {
                return $variantType;
            }
        }

        if (isset($data['eSimDetails']['variantType'])) {
            $variantType = strtolower(trim($data['eSimDetails']['variantType']));
            if (in_array($variantType, ['basic', 'unlimited'])) {
                return $variantType;
            }
        }

        // Try to get variant info from the order's eSIM data if available
        if ($this->messageId) {
            return $this->getVariantTypeFromOrderESim();
        }

        return null;
    }



    /**
     * Validate insurance person data structure (updated for real input structure)
     */
    protected function validatePersonData(array $personData, string $personType): bool
    {
        // Core required fields that must exist based on actual input structure
        $requiredFields = ['firstname', 'lastname', 'idType', 'idNumber', 'dob', 'email'];

        foreach ($requiredFields as $field) {
            if (! isset($personData[$field]) || empty($personData[$field])) {
                return false;
            }
        }

        // Validate plan structure - based on real input structure
        if (! isset($personData['plan']) || ! is_array($personData['plan'])) {
            return false;
        }

        // Plan should have at least id and name (based on real input)
        if (! isset($personData['plan']['id']) || ! isset($personData['plan']['name'])) {
            return false;
        }

        // activationDate is optional but commonly present
        // Other fields like productName, originCountryCode, etc. are also optional

        return true;
    }

    /**
     * Process titular (main applicant) insurance with dual quotation workflow
     */
    protected function processTitular(array $titularData): array
    {
        // Validate titular data structure
        if (! $this->validatePersonData($titularData, 'titular')) {
            throw new ValidationException('Invalid titular data structure');
        }

        // Extract origin and destination country codes from input data
        $originCountryCode = $titularData['originCountryCode'] ?? 'AR'; // Default to Argentina
        $destinationCountryCode = $titularData['destinationCountryCode'] ?? $titularData['destinyCountryCode'] ?? 'DO'; // Default to Dominican Republic

        // Perform dual quotation workflow - always get both inclusion and cross selling quotations
        $dualQuotationResult = $this->performDualQuotationWorkflow($titularData, $originCountryCode, $destinationCountryCode);

        // Find the best matching plan based on variant type and quotation results
        $selectedQuotation = $this->selectBestQuotationForVoucher($titularData, $dualQuotationResult);

        // Create voucher using the selected quotation's IdLead and plan information
        $voucherResult = $this->createVoucherFromSelectedQuotation($titularData, $selectedQuotation, $originCountryCode, $destinationCountryCode, 'titular');

        // Combine all results
        $result = [
            'dual_quotation_results' => $dualQuotationResult,
            'selected_quotation' => $selectedQuotation,
            'voucher_result' => $voucherResult,
            'workflow_type' => 'dual_quotation_with_plan_matching'
        ];

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Store titular voucher information in eSim message metadata
        $this->storeVoucherInESimMessageMetadata($titularData, $result, 'titular');

        return $result;
    }

    /**
     * Process dependent insurance with dual quotation workflow
     * Each dependent gets their own voucher since they have individual plans to pay
     */
    protected function processDependent(array $dependentData, string $titularOriginCountryCode, string $titularDestinationCountryCode): array
    {
        // Validate dependent data structure
        if (! $this->validatePersonData($dependentData, 'dependent')) {
            throw new ValidationException('Invalid dependent data structure');
        }

        // Perform dual quotation workflow - always get both inclusion and cross selling quotations
        $dualQuotationResult = $this->performDualQuotationWorkflow($dependentData, $titularOriginCountryCode, $titularDestinationCountryCode);

        // Find the best matching plan based on variant type and quotation results
        $selectedQuotation = $this->selectBestQuotationForVoucher($dependentData, $dualQuotationResult);

        // Create voucher using the selected quotation's IdLead and plan information
        $voucherResult = $this->createVoucherFromSelectedQuotation($dependentData, $selectedQuotation, $titularOriginCountryCode, $titularDestinationCountryCode, 'dependent');

        // Combine all results
        $result = [
            'dual_quotation_results' => $dualQuotationResult,
            'selected_quotation' => $selectedQuotation,
            'voucher_result' => $voucherResult,
            'workflow_type' => 'dual_quotation_with_plan_matching'
        ];

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Store dependent voucher information in eSim message metadata
        $this->storeVoucherInESimMessageMetadata($dependentData, $result, 'dependent');

        return $result;
    }

    /**
     * Build voucher data for Universal Assistance from cart data
     */
    protected function buildVoucherData(array $personData, string $personType, string $originCountryCode = 'AR', string $destinationCountryCode = 'DO'): array
    {
        // Convert destinationCountryCode to destination name (based on real input structure)
        $destination = $this->getDestinationName($destinationCountryCode);

        // Validate destination
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates based on activation date and product duration
        $activationDate = Carbon::parse($personData['activationDate']);

        // Get duration from product attributes (1, 3, 7, 15, or 30 days)
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration); // Duration days from activation date

        // DEPRECATED: This method should not be used - convenio must be determined by variant logic
        // Use buildVoucherDataWithConvenio() instead with proper variant-based convenio selection
        throw new ValidationException("buildVoucherData is deprecated. Use buildVoucherDataWithConvenio() with variant-based convenio selection instead of country-based logic.");

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // Empty price for voucher creation as requested
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract, // Using country-based convenio logic
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N', // Campo "imprime tarifa" en "N" como solicitado
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7', // QA fallback
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'], // Use the actual plan name from cart data
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => $this->getDocumentType($personData['idType']),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => $this->getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }

    /**
     * Build Cross Selling voucher data with different pricing and enhanced features
     */
    protected function buildCrossSellingVoucherData(array $personData, string $personType, string $originCountryCode = 'AR', string $destinationCountryCode = 'DO'): array
    {
        // Convert destinationCountryCode to destination name (based on real input structure)
        $destination = $this->getDestinationName($destinationCountryCode);

        // Validate destination
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates
        $activationDate = Carbon::parse($personData['activationDate']);
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration); // Duration days from activation date

        // DEPRECATED: This method should not be used - convenio must be determined by variant logic
        // Use buildCrossSellingVoucherDataWithConvenio() instead with proper variant-based convenio selection
        throw new ValidationException("buildCrossSellingVoucherData is deprecated. Use buildCrossSellingVoucherDataWithConvenio() with variant-based convenio selection instead of country-based logic.");

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination, // Use proper destination instead of 'Mundial'
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // Empty price for voucher creation as requested
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract, // Using country-based convenio logic for Cross Selling
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7', // QA fallback
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'], // Use the actual plan name from cart data
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => $this->getDocumentType($personData['idType']),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => $this->getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }

    /**
     * Convert country code to destination name
     */
    protected function getDestinationName(string $countryCode): string
    {
        // Map country codes to Universal Assistance valid destinations
        $countryToDestination = [
            // Territorio Nacional (República Dominicana)
            'DO' => 'Territorio Nacional',

            // Centro america/Caribe
            'PA' => 'Centro america/Caribe',
            'CR' => 'Centro america/Caribe',
            'GT' => 'Centro america/Caribe',
            'HN' => 'Centro america/Caribe',
            'SV' => 'Centro america/Caribe',
            'NI' => 'Centro america/Caribe',
            'BZ' => 'Centro america/Caribe',
            'JM' => 'Centro america/Caribe',
            'CU' => 'Centro america/Caribe',
            'HT' => 'Centro america/Caribe',
            'TT' => 'Centro america/Caribe',
            'BB' => 'Centro america/Caribe',
            'BS' => 'Centro america/Caribe',
            'PR' => 'Centro america/Caribe',

            // America del norte
            'US' => 'America del norte',
            'CA' => 'America del norte',
            'MX' => 'America del norte',

            // América del Sur (salvo Vzla)
            'AR' => 'América del Sur (salvo Vzla)',
            'BR' => 'América del Sur (salvo Vzla)',
            'CL' => 'América del Sur (salvo Vzla)',
            'CO' => 'América del Sur (salvo Vzla)',
            'PE' => 'América del Sur (salvo Vzla)',
            'UY' => 'América del Sur (salvo Vzla)',
            'PY' => 'América del Sur (salvo Vzla)',
            'EC' => 'América del Sur (salvo Vzla)',
            'BO' => 'América del Sur (salvo Vzla)',
            'GY' => 'América del Sur (salvo Vzla)',
            'SR' => 'América del Sur (salvo Vzla)',
            'GF' => 'América del Sur (salvo Vzla)',
            // Note: Venezuela (VE) is excluded as per "salvo Vzla"

            // Europa
            'ES' => 'Europa',
            'FR' => 'Europa',
            'IT' => 'Europa',
            'DE' => 'Europa',
            'GB' => 'Europa',
            'PT' => 'Europa',
            'NL' => 'Europa',
            'BE' => 'Europa',
            'CH' => 'Europa',
            'AT' => 'Europa',
            'SE' => 'Europa',
            'NO' => 'Europa',
            'DK' => 'Europa',
            'FI' => 'Europa',
            'IE' => 'Europa',
            'GR' => 'Europa',
            'PL' => 'Europa',
            'CZ' => 'Europa',
            'HU' => 'Europa',
            'RO' => 'Europa',
            'BG' => 'Europa',
            'HR' => 'Europa',
            'SK' => 'Europa',
            'SI' => 'Europa',
            'EE' => 'Europa',
            'LV' => 'Europa',
            'LT' => 'Europa',
            'LU' => 'Europa',
            'MT' => 'Europa',
            'CY' => 'Europa',

            // Asia
            'CN' => 'Asia',
            'JP' => 'Asia',
            'KR' => 'Asia',
            'IN' => 'Asia',
            'TH' => 'Asia',
            'VN' => 'Asia',
            'SG' => 'Asia',
            'MY' => 'Asia',
            'ID' => 'Asia',
            'PH' => 'Asia',
            'HK' => 'Asia',
            'TW' => 'Asia',
            'AE' => 'Asia',
            'SA' => 'Asia',
            'IL' => 'Asia',
            'TR' => 'Asia',
            'RU' => 'Asia',

            // Africa
            'ZA' => 'Africa',
            'EG' => 'Africa',
            'MA' => 'Africa',
            'NG' => 'Africa',
            'KE' => 'Africa',
            'GH' => 'Africa',
            'TN' => 'Africa',
            'DZ' => 'Africa',
            'ET' => 'Africa',
            'UG' => 'Africa',
            'TZ' => 'Africa',
            'MZ' => 'Africa',
            'ZM' => 'Africa',
            'ZW' => 'Africa',
            'BW' => 'Africa',
            'NA' => 'Africa',
            'SN' => 'Africa',
            'CI' => 'Africa',
            'ML' => 'Africa',
            'BF' => 'Africa',
            'NE' => 'Africa',
            'TD' => 'Africa',
            'CM' => 'Africa',
            'AO' => 'Africa',

            // Oceanía
            'AU' => 'Oceanía',
            'NZ' => 'Oceanía',
            'FJ' => 'Oceanía',
            'PG' => 'Oceanía',
            'NC' => 'Oceanía',
            'VU' => 'Oceanía',
            'SB' => 'Oceanía',
            'TO' => 'Oceanía',
            'WS' => 'Oceanía',
            'KI' => 'Oceanía',
            'TV' => 'Oceanía',
            'NR' => 'Oceanía',
            'PW' => 'Oceanía',
            'FM' => 'Oceanía',
            'MH' => 'Oceanía',
        ];

        $destination = $countryToDestination[$countryCode] ?? 'Centro america/Caribe'; // Default fallback

        return $destination;
    }

    /**
     * Get all valid Universal Assistance destinations
     */
    protected function getValidDestinations(): array
    {
        return [
            'Africa',
            'America del norte',
            'América del Sur (salvo Vzla)',
            'Asia',
            'Centro america/Caribe',
            'Europa',
            'Oceanía',
            'Territorio Nacional'
        ];
    }

    /**
     * Validate if a destination is valid for Universal Assistance
     */
    protected function isValidDestination(string $destination): bool
    {
        return in_array($destination, $this->getValidDestinations());
    }

    /**
     * Convert document type to Universal Assistance format
     */
    protected function getDocumentType(string $idType): string
    {
        $types = [
            'passport' => 'Pasaporte',
            'dni' => 'DNI',
            'cedula' => 'DNI', // Map cedula to DNI
            'license' => 'DNI', // Map license to DNI as fallback
        ];

        return $types[$idType] ?? 'Pasaporte'; // Default to DNI instead of PASAPORTE
    }

    /**
     * Convert country code to country name
     */
    protected function getCountryName(string $countryCode): string
    {
        $countries = [
            'AR' => 'ARGENTINA',
            'DO' => 'REPUBLICA DOMINICANA',
            'US' => 'ESTADOS UNIDOS',
            'CA' => 'CANADA',
            'MX' => 'MEXICO',
            'ES' => 'ESPAÑA',
            'FR' => 'FRANCIA',
            'IT' => 'ITALIA',
            'BR' => 'BRASIL',
            'CO' => 'COLOMBIA',
        ];

        return $countries[$countryCode] ?? 'REPUBLICA DOMINICANA'; // Default to a valid country instead of INTERNACIONAL
    }

    /**
     * Determine plan type from plan data
     */
    protected function determinePlanType(array $personData): string
    {
        // Check if plan has type information
        $planType = $personData['plan']['type'] ??
                   $personData['plan']['attributes']['type'] ??
                   $personData['planType'] ??
                   null;

        // Check plan name for indicators
        $planName = strtolower($personData['plan']['name'] ?? '');

        // If explicitly set, use it
        if ($planType) {
            $planTypeNormalized = strtolower($planType);
            if (in_array($planTypeNormalized, ['cross_selling', 'cross-selling', 'crossselling'])) {
                return 'cross_selling';
            }
            if (in_array($planTypeNormalized, ['inclusion', 'inclusión', 'incluso'])) {
                return 'inclusion';
            }
        }

        // Check plan name for cross-selling indicators
        if (strpos($planName, 'cross') !== false ||
            strpos($planName, 'venta cruzada') !== false ||
            strpos($planName, 'premium') !== false ||
            strpos($planName, 'plus') !== false) {
            return 'cross_selling';
        }

        // Default to inclusion
        return 'inclusion';
    }

    /**
     * Get product duration from plan attributes
     * Use whatever duration the plan specifies without validation
     */
    protected function getProductDuration(array $personData): int
    {
        // Try to get duration from different possible locations
        $duration = $personData['plan']['duration'] ??
                   $personData['plan']['attributes']['duration'] ??
                   $personData['duration'] ??
                   null;

        // If duration is provided, use it directly without validation
        if ($duration !== null && $duration !== '') {
            $durationInt = (int) $duration;

            if ($durationInt > 0) {
                return $durationInt;
            }
        }

        // Fallback: calculate from activation and expiration dates if available
        if (isset($personData['activationDate']) && isset($personData['expirationDate'])) {
            try {
                $activationDate = Carbon::parse($personData['activationDate']);
                $expirationDate = Carbon::parse($personData['expirationDate']);
                $calculatedDuration = (int)($activationDate->diffInDays($expirationDate) + 1); // +1 to include both dates

                // Use calculated duration directly without validation
                if ($calculatedDuration > 0) {
                    return $calculatedDuration;
                }
            } catch (\Exception $e) {
                // Ignore date parsing errors
            }
        }

        // Default to 7 days if no valid duration found
        return 7;
    }

    /**
     * Store voucher information in eSim message metadata for both titular and dependents
     */
    protected function storeVoucherInESimMessageMetadata(array $personData, array $voucherResult, string $personType): void
    {
        if (! $this->messageId) {
            return;
        }

        // Convert voucher result to arrays to prevent stdClass errors
        $voucherResult = $this->convertObjectsToArrays($voucherResult);

        $message = Message::getById($this->messageId);
        $messageData = $message->message;

        if (! isset($messageData['universalAssistanceData'])) {
            $messageData['universalAssistanceData'] = [];
        }

        if (! isset($messageData['universalAssistanceData']['vouchers'])) {
            $messageData['universalAssistanceData']['vouchers'] = [];
        }

        // Extract quotation information from the response
        $quoteData = $voucherResult['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    $voucherResult['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    [];

        // Find the specific product that matches the requested plan using new method
        $matchedProduct = $this->findMatchingProductInQuoteData($personData['plan']['name'] ?? '', $quoteData);

        // Store complete voucher information with quotation details
        $voucherInfo = [
            'person_type' => $personType, // 'titular' or 'dependent'
            'person_info' => [
                'name' => $personData['firstname'] . ' ' . $personData['lastname'],
                'firstname' => $personData['firstname'],
                'lastname' => $personData['lastname'],
                'id_type' => $personData['idType'],
                'id_number' => $personData['idNumber'],
                'date_of_birth' => $personData['dob'],
                'email' => $personData['email'],
                'activation_date' => $personData['activationDate'],
                'origin_country_code' => $personData['originCountryCode'] ?? 'DO',
                'destination_country_code' => $personData['destinationCountryCode'] ?? $personData['destinyCountryCode'] ?? 'US',
            ],
            'plan' => [
                'name' => $personData['plan']['name'] ?? null,
                'type' => $this->determinePlanType($personData),
                'price' => $personData['plan']['price'] ?? null,
                'duration' => $personData['plan']['duration'] ?? $personData['duration'] ?? null,
                'attributes' => $personData['plan']['attributes'] ?? null,
            ],
            'quotation_details' => [
                // Main quote information (always from main response)
                'precio_emision' => $quoteData['PrecioEmision'] ?? null,
                'precio_emision_local' => $quoteData['PrecioEmisionLocal'] ?? null,
                'precio_bruto' => $quoteData['PrecioBruto'] ?? null,
                'precio_bruto_local' => $quoteData['PrecioBrutoLocal'] ?? null,
                'precio_unitario' => $quoteData['PrecioUnitario'] ?? null,
                'precio_neto' => $quoteData['PrecioNeto'] ?? null,
                'precio_neto_local' => $quoteData['PrecioNetoLocal'] ?? null,
                'moneda_lista' => $quoteData['MonedaLista'] ?? null,
                'moneda_local' => $quoteData['MonedaLocal'] ?? null,
                'tipo_cambio' => $quoteData['TipoCambio'] ?? null,
                'nombre_producto' => $quoteData['NombreProducto'] ?? null,
                'producto' => $quoteData['Producto'] ?? null,
                'familia_producto' => $quoteData['FamiliaProducto'] ?? null,
                'id_producto' => $quoteData['IdProducto'] ?? null,
                'id_lead' => $quoteData['IdLeadOut'] ?? null,
                'ambito_geografico' => $quoteData['AmbitoGeografico'] ?? null,
                'categoria' => $quoteData['Categoria'] ?? null,
                'gravabilidad' => $quoteData['Gravabilidad'] ?? null,
                'porcentaje_gravabilidad' => $quoteData['PorcentajeGravabilidad'] ?? null,
                'marca' => $quoteData['Marca'] ?? null,
                'logo' => $quoteData['Logo'] ?? null,
                'quoted_at' => now()->toISOString(),
                // Matched product specific information
                'matched_product' => [
                    'found' => $matchedProduct['found'] ?? false,
                    'source' => $matchedProduct['source'] ?? null,
                    'product_name' => $matchedProduct['product_name'] ?? null,
                    'attribute_index' => $matchedProduct['attribute_index'] ?? null,
                    'match_score' => $matchedProduct['match_score'] ?? null,
                    'specific_product_data' => $matchedProduct['attribute_data'] ?? null,
                ],
            ],
            'product_validation' => [
                'plan_requested' => $personData['plan']['name'] ?? null,
                'product_search_result' => $matchedProduct,
                'product_quoted' => $matchedProduct['found'] ? $matchedProduct['product_name'] : ($quoteData['NombreProducto'] ?? null),
                'product_match' => $matchedProduct['found'] ? $matchedProduct['match_details'] : ['match' => false, 'reason' => 'Product not found in quote'],
                'validation_timestamp' => now()->toISOString(),
                'note' => 'Price validation skipped - voucher has empty price by design'
            ],
            'voucher_data' => [
                'control_number' => $voucherResult['control_number'] ?? null,
                'nro_voucher' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ??
                               $voucherResult['voucher_response']['NroVoucher'] ?? null,
                'nro_control_ext' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroControlExt'] ??
                                   $voucherResult['voucher_response']['NroControlExt'] ?? null,
                'error_code' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode'] ??
                              $voucherResult['voucher_response']['ErrorCode'] ?? null,
                'error_msg' => $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorMsg'] ??
                             $voucherResult['voucher_response']['ErrorMsg'] ?? null,
                'voucher_id' => $voucherResult['voucher_response']['IdVoucher'] ?? null,
                'quotation_type' => $voucherResult['quotation_type'] ?? null,
                'organization' => $voucherResult['organization'] ?? null,
                'convenio' => $voucherResult['convenio'] ?? null,
                'origin_used' => $voucherResult['origin_used'] ?? null,
                'destination_used' => $voucherResult['destination_used'] ?? null,
                'voucher_success' => ($voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['ErrorCode'] ??
                                    $voucherResult['voucher_response']['ErrorCode'] ?? null) === '00',
                'status' => 'active',
                'created_at' => now()->toISOString(),
            ],
            'pdf_data' => [
                'success' => $voucherResult['pdf_data']['success'] ?? false,
                'pdf_url' => $voucherResult['pdf_data']['pdf_url'] ?? null,
                'error' => $voucherResult['pdf_data']['error'] ?? null,
                'generated_at' => $voucherResult['pdf_data']['generated_at'] ?? null,
                'raw_response' => $voucherResult['pdf_data']['raw_response'] ?? null,
            ],
            'has_individual_voucher' => true,
        ];

        // Add minor flag if applicable
        if (isset($personData['dob'])) {
            try {
                $birthDate = Carbon::parse($personData['dob']);
                $age = $birthDate->diffInYears(now());
                if ($age < 18) {
                    $voucherInfo['person_info']['minor'] = true;
                }
            } catch (\Exception $e) {
                // Ignore age calculation errors
            }
        }

        $messageData['universalAssistanceData']['vouchers'][] = $voucherInfo;

        $message->message = $messageData;
        $message->saveOrFail();
    }

    /**
     * Validate if the product quoted matches the requested plan
     */
    protected function validateProductMatch(?string $planRequested, ?string $productQuoted): array
    {
        if (! $planRequested || ! $productQuoted) {
            return [
                'match' => false,
                'reason' => 'Missing plan or product information',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted
            ];
        }

        // Normalize strings for comparison
        $planNormalized = strtolower(trim($planRequested));
        $productNormalized = strtolower(trim($productQuoted));

        // Exact match
        if ($planNormalized === $productNormalized) {
            return [
                'match' => true,
                'match_type' => 'exact',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted
            ];
        }

        // Check if plan name is contained in product name
        if (strpos($productNormalized, $planNormalized) !== false) {
            return [
                'match' => true,
                'match_type' => 'partial_plan_in_product',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted
            ];
        }

        // Check if product name is contained in plan name
        if (strpos($planNormalized, $productNormalized) !== false) {
            return [
                'match' => true,
                'match_type' => 'partial_product_in_plan',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted
            ];
        }

        // Check for key words matching (for similar products)
        $planWords = array_filter(explode(' ', $planNormalized));
        $productWords = array_filter(explode(' ', $productNormalized));

        $commonWords = array_intersect($planWords, $productWords);
        $matchPercentage = count($commonWords) / max(count($planWords), count($productWords), 1);

        if ($matchPercentage >= 0.5) { // 50% or more words match
            return [
                'match' => true,
                'match_type' => 'keyword_similarity',
                'match_percentage' => round($matchPercentage * 100, 2),
                'common_words' => array_values($commonWords),
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted
            ];
        }

        // No match found
        return [
            'match' => false,
            'reason' => 'No sufficient similarity found',
            'match_percentage' => round($matchPercentage * 100, 2),
            'plan_requested' => $planRequested,
            'product_quoted' => $productQuoted
        ];
    }

    /**
     * Validate pricing information from quotation
     */
    protected function validatePricing(array $personData, array $quoteData): array
    {
        $planPrice = $personData['plan']['price'] ?? null;
        $quotedPrice = $quoteData['PrecioEmision'] ?? null;
        $quotedCurrency = $quoteData['MonedaLista'] ?? null;

        $validation = [
            'plan_price' => $planPrice,
            'quoted_price' => $quotedPrice,
            'quoted_currency' => $quotedCurrency,
            'price_match' => false,
            'price_difference' => null,
            'validation_notes' => []
        ];

        if ($planPrice !== null && $quotedPrice !== null) {
            $planPriceFloat = (float) $planPrice;
            $quotedPriceFloat = (float) $quotedPrice;

            if ($planPriceFloat === $quotedPriceFloat) {
                $validation['price_match'] = true;
                $validation['match_type'] = 'exact';
            } else {
                $validation['price_difference'] = $quotedPriceFloat - $planPriceFloat;
                $validation['price_difference_percentage'] = $planPriceFloat > 0 ?
                    round(($validation['price_difference'] / $planPriceFloat) * 100, 2) : null;

                // Consider close matches (within 5% or $1)
                $tolerance = max(0.05 * $planPriceFloat, 1.0);
                if (abs($validation['price_difference']) <= $tolerance) {
                    $validation['price_match'] = true;
                    $validation['match_type'] = 'within_tolerance';
                    $validation['validation_notes'][] = 'Price within acceptable tolerance';
                }
            }
        } else {
            if ($planPrice === null) {
                $validation['validation_notes'][] = 'No plan price available for comparison';
            }
            if ($quotedPrice === null) {
                $validation['validation_notes'][] = 'No quoted price received';
            }
        }

        return $validation;
    }

    /**
     * Validate pricing using the matched product information
     */
    protected function validatePricingWithMatchedProduct(array $personData, array $quoteData, array $matchedProduct): array
    {
        $planPrice = $personData['plan']['price'] ?? null;

        // Only validate pricing if product was matched - NO FALLBACKS
        $quotedPrice = null;
        $priceSource = 'not_found';

        if ($matchedProduct['found']) {
            // Safety check for source key
            $source = $matchedProduct['source'] ?? 'unknown';

            if ($source === 'main_product') {
                // Use main product pricing
                $quotedPrice = $quoteData['PrecioEmision'] ?? $quoteData['PrecioNeto'] ?? null;
                $priceSource = 'main_product';
            } elseif (isset($matchedProduct['attribute_data'])) {
                // Try to find price in the matched attribute
                $attributeData = $matchedProduct['attribute_data'];
                $quotedPrice = $attributeData['PrecioEmision'] ??
                              $attributeData['Precio'] ??
                              $attributeData['Valor'] ??
                              $attributeData['price'] ??
                              $attributeData['amount'] ?? null;

                if ($quotedPrice !== null) {
                    $priceSource = 'matched_attribute';
                }
            }
        }

        $quotedCurrency = $quoteData['MonedaLista'] ?? null;

        $validation = [
            'plan_price' => $planPrice,
            'quoted_price' => $quotedPrice,
            'quoted_currency' => $quotedCurrency,
            'price_source' => $priceSource,
            'price_match' => false,
            'price_difference' => null,
            'validation_notes' => []
        ];

        if ($planPrice !== null && $quotedPrice !== null) {
            // Convert to numeric values for comparison
            $planPriceFloat = (float) $planPrice;
            $quotedPriceFloat = (float) $quotedPrice;

            if ($planPriceFloat === $quotedPriceFloat) {
                $validation['price_match'] = true;
                $validation['match_type'] = 'exact';
            } else {
                $validation['price_difference'] = $quotedPriceFloat - $planPriceFloat;
                $validation['price_difference_percentage'] = $planPriceFloat > 0 ?
                    round(($validation['price_difference'] / $planPriceFloat) * 100, 2) : null;

                // Consider close matches (within 5% or $1)
                $tolerance = max(0.05 * $planPriceFloat, 1.0);
                if (abs($validation['price_difference']) <= $tolerance) {
                    $validation['price_match'] = true;
                    $validation['match_type'] = 'within_tolerance';
                    $validation['validation_notes'][] = 'Price within acceptable tolerance';
                }
            }
        } else {
            if ($planPrice === null) {
                $validation['validation_notes'][] = 'No plan price available for comparison';
            }
            if ($quotedPrice === null) {
                $validation['validation_notes'][] = 'No quoted price found for matched product';
            }
        }

        // Add notes about product matching - NO FALLBACK MESSAGING
        if ($matchedProduct['found']) {
            $validation['validation_notes'][] = "Price extracted from {$priceSource} for matched product: {$matchedProduct['product_name']}";
        } else {
            $validation['validation_notes'][] = 'Product not matched - no pricing validation performed';
        }

        return $validation;
    }

    /**
     * Find the specific product in the quote response that matches the requested plan
     */
    protected function findMatchingProductInQuote(?string $requestedPlanName, array $quoteData): array
    {
        if (! $requestedPlanName || empty($quoteData)) {
            return [
                'found' => false,
                'reason' => 'Missing plan name or quote data'
            ];
        }

        // First check the main product in the quote
        $mainProductName = $quoteData['NombreProducto'] ?? null;
        if ($mainProductName) {
            $mainProductMatch = $this->validateProductMatch($requestedPlanName, $mainProductName);
            if ($mainProductMatch['match']) {
                return [
                    'found' => true,
                    'source' => 'main_product',
                    'product_name' => $mainProductName,
                    'match_details' => $mainProductMatch,
                    'quote_data' => $quoteData
                ];
            }
        }

        // Search in attributes/products array if available
        $attributes = $quoteData['Atributo'] ?? $quoteData['attributes'] ?? $quoteData['productos'] ?? [];
        if (! empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $index => $attribute) {
                // Check different possible field names for product name
                $productName = $attribute['NombreProducto'] ??
                              $attribute['NombreVisible'] ??
                              $attribute['Nombre'] ??
                              $attribute['product_name'] ??
                              $attribute['name'] ?? null;

                if ($productName) {
                    $attributeMatch = $this->validateProductMatch($requestedPlanName, $productName);
                    if ($attributeMatch['match']) {
                        return [
                            'found' => true,
                            'source' => 'attribute',
                            'attribute_index' => $index,
                            'product_name' => $productName,
                            'match_details' => $attributeMatch,
                            'attribute_data' => $attribute,
                            'quote_data' => $quoteData
                        ];
                    }
                }
            }
        }

        // If no exact match found in attributes, try to find partial matches
        $bestMatch = null;
        $bestScore = 0;

        if (! empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $index => $attribute) {
                $productName = $attribute['NombreProducto'] ??
                              $attribute['NombreVisible'] ??
                              $attribute['Nombre'] ??
                              $attribute['product_name'] ??
                              $attribute['name'] ?? null;

                if ($productName) {
                    $attributeMatch = $this->validateProductMatch($requestedPlanName, $productName);
                    if (isset($attributeMatch['match_percentage']) && $attributeMatch['match_percentage'] > $bestScore) {
                        $bestScore = $attributeMatch['match_percentage'];
                        $bestMatch = [
                            'found' => false, // Not a strong match, but best available
                            'source' => 'best_partial_match',
                            'attribute_index' => $index,
                            'product_name' => $productName,
                            'match_details' => $attributeMatch,
                            'attribute_data' => $attribute,
                            'quote_data' => $quoteData,
                            'match_score' => $bestScore
                        ];
                    }
                }
            }
        }

        // Return best partial match if found, otherwise return not found
        if ($bestMatch && $bestScore >= 30) { // At least 30% similarity
            $bestMatch['found'] = true;
            $bestMatch['source'] = 'partial_match';
            return $bestMatch;
        }

        return [
            'found' => false,
            'reason' => 'No matching product found in quote response',
            'requested_plan' => $requestedPlanName,
            'available_products' => $this->extractAvailableProductNames($quoteData),
            'main_product' => $mainProductName
        ];
    }

    /**
     * Extract all available product names from quote data for debugging
     */
    protected function extractAvailableProductNames(array $quoteData): array
    {
        $products = [];

        // Add main product
        if (isset($quoteData['NombreProducto'])) {
            $products[] = $quoteData['NombreProducto'];
        }

        // Add products from attributes
        $attributes = $quoteData['Atributo'] ?? $quoteData['attributes'] ?? $quoteData['productos'] ?? [];
        if (! empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $attribute) {
                $productName = $attribute['NombreProducto'] ??
                              $attribute['NombreVisible'] ??
                              $attribute['Nombre'] ??
                              $attribute['product_name'] ??
                              $attribute['name'] ?? null;

                if ($productName && ! in_array($productName, $products)) {
                    $products[] = $productName;
                }
            }
        }

        return $products;
    }

    /**
     * Store dependent information in eSim message metadata (same level as AeroAmbulancia)
     * @deprecated Use storeVoucherInESimMessageMetadata instead
     */
    protected function storeDependentInESimMessageMetadata(array $dependentData): void
    {
        if (! $this->messageId) {
            return;
        }

        $message = Message::getById($this->messageId);
        $messageData = $message->message;

        if (! isset($messageData['universalAssistanceData'])) {
            $messageData['universalAssistanceData'] = [];
        }

        if (! isset($messageData['universalAssistanceData']['dependents'])) {
            $messageData['universalAssistanceData']['dependents'] = [];
        }

        // Store essential dependent information
        $dependentInfo = [
            'name' => $dependentData['firstname'] . ' ' . $dependentData['lastname'],
            'firstname' => $dependentData['firstname'],
            'lastname' => $dependentData['lastname'],
            'id_type' => $dependentData['idType'],
            'id_number' => $dependentData['idNumber'],
            'date_of_birth' => $dependentData['dob'],
            'email' => $dependentData['email'],
            'activation_date' => $dependentData['activationDate'],
            'origin_country_code' => $dependentData['originCountryCode'] ?? 'DO',
            'destiny_country_code' => $dependentData['destinationCountryCode'] ?? $dependentData['destinyCountryCode'] ?? 'US',
            'plan' => [
                'name' => $dependentData['plan']['name'] ?? null,
                'type' => $this->determinePlanType($dependentData),
                'price' => $dependentData['plan']['price'] ?? null,
                'duration' => $dependentData['plan']['duration'] ?? $dependentData['duration'] ?? null,
                'attributes' => $dependentData['plan']['attributes'] ?? null,
            ],
            'stored_at' => now()->toISOString(),
            'covered_under_titular_voucher' => true,
        ];

        // Add minor flag if applicable
        if (isset($dependentData['dob'])) {
            try {
                $birthDate = Carbon::parse($dependentData['dob']);
                $age = $birthDate->diffInYears(now());
                if ($age < 18) {
                    $dependentInfo['minor'] = true;
                }
            } catch (\Exception $e) {
                // Ignore age calculation errors
            }
        }

        $messageData['universalAssistanceData']['dependents'][] = $dependentInfo;

        $message->message = $messageData;
        $message->saveOrFail();
    }

    /**
     * Generate PDF for a voucher using the sendReport method
     */
    protected function generateVoucherPDF(array $voucherResult, array $personData): array
    {
        $pdfResult = [
            'success' => false,
            'pdf_url' => null,
            'error' => null,
            'generated_at' => now()->toISOString(),
            'person_name' => ($personData['firstname'] ?? '') . ' ' . ($personData['lastname'] ?? ''),
        ];

        try {
            // Extract voucher number from the response - NroVoucher is the actual voucher ID
            $voucherNumber = $voucherResult['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'] ??
                            $voucherResult['voucher_response']['NroVoucher'] ??
                            null;

            if (! $voucherNumber) {
                $pdfResult['error'] = 'No voucher number (NroVoucher) found in voucher response to generate PDF';
                $pdfResult['voucher_response_keys'] = array_keys($voucherResult['voucher_response'] ?? []);
                return $pdfResult;
            }

            // Extract quotation data to get additional information
            $quoteData = $voucherResult['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                        $voucherResult['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                        [];

            // Build report data for PDF generation using the NroVoucher
            // Include more comprehensive data that might be needed by the PDF service
            $reportData = [
                'Language' => 'Spanish',  // Default to Spanish, could be 'English' based on person data
                'VoucherNumber' => $voucherNumber, // This should be the NroVoucher (e.g., T417502009)
                'Tarifa' => $this->buildTarifaParameter($quoteData, $personData), // Build proper tarifa parameter
                'Organization' => $voucherResult['organization'] ?? $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?? '1-ENYNUF7',
            ];

            // Validate essential parameters before calling PDF service
            if (empty($reportData['VoucherNumber'])) {
                $pdfResult['error'] = 'VoucherNumber parameter is required for PDF generation';
                return $pdfResult;
            }

            if (empty($reportData['Organization'])) {
                $pdfResult['error'] = 'Organization parameter is required for PDF generation';
                return $pdfResult;
            }

            // Add debug info to help with troubleshooting
            $pdfResult['request_data'] = $reportData;

            // Generate PDF using the sendReport method
            $pdfResponse = $this->client->sendReport($reportData, false);

            if (is_array($pdfResponse) && ! empty($pdfResponse)) {
                // Check for Universal Assistance service errors first
                if (isset($pdfResponse['Error_spcCode']) || isset($pdfResponse['Error_spcMessage'])) {
                    $errorCode = $pdfResponse['Error_spcCode'] ?? 'Unknown';
                    $errorMessage = $pdfResponse['Error_spcMessage'] ?? 'Unknown error';

                    $pdfResult['error'] = "Universal Assistance PDF service error [{$errorCode}]: {$errorMessage}";
                    $pdfResult['service_error'] = true;
                    $pdfResult['error_code'] = $errorCode;
                    $pdfResult['error_message'] = $errorMessage;
                    $pdfResult['raw_response'] = $pdfResponse;

                    return $pdfResult;
                }

                // Check if PDF was generated successfully based on WSDL structure
                // According to WSDL, the PDF comes in SM.ListOfUaSendReportIo.UaVoucherBc.ListOfUaImpresionSimplificadaBc.UaImpresionSimplificadaBc.ReportOutputFileBuffer
                $pdfExtracted = $this->extractPdfFromSendReportResponse($pdfResponse);

                if ($pdfExtracted['success']) {
                    $pdfResult['success'] = true;
                    $pdfResult['pdf_url'] = $pdfExtracted['pdf_url'];
                    $pdfResult['pdf_data'] = $pdfExtracted['pdf_data'] ?? null;
                    $pdfResult['file_name'] = $pdfExtracted['file_name'] ?? null;
                } else {
                    // Enhanced fallback - check for direct PDF fields and any base64 data
                    $foundPdf = false;

                    // Check common PDF field names
                    $pdfFields = ['PDFUrl', 'Url', 'Link', 'ReportUrl', 'DownloadUrl', 'FileUrl'];
                    $dataFields = ['PDFData', 'Data', 'ReportData', 'FileBuffer', 'Buffer', 'Content'];

                    foreach ($pdfFields as $field) {
                        if (isset($pdfResponse[$field]) && ! empty($pdfResponse[$field])) {
                            $pdfResult['success'] = true;
                            $pdfResult['pdf_url'] = $pdfResponse[$field];
                            $foundPdf = true;
                            break;
                        }
                    }

                    if (! $foundPdf) {
                        foreach ($dataFields as $field) {
                            if (isset($pdfResponse[$field]) && ! empty($pdfResponse[$field])) {
                                $pdfData = $pdfResponse[$field];
                                // Validate if it's likely PDF data
                                if (is_string($pdfData) && strlen($pdfData) > 100) {
                                    $pdfResult['success'] = true;
                                    $pdfResult['pdf_data'] = $pdfData;
                                    $pdfResult['pdf_url'] = 'data:application/pdf;base64,' . $pdfData;
                                    $foundPdf = true;
                                    break;
                                }
                            }
                        }
                    }

                    if (! $foundPdf) {
                        // Deep search for any URL-like values in the response
                        $this->findUrlsInResponse($pdfResponse, $pdfResult);

                        if (! $pdfResult['success']) {
                            // Last resort: look for any base64-like data that could be PDF
                            $this->findBase64DataInResponse($pdfResponse, $pdfResult);
                        }

                        if (! $pdfResult['success']) {
                            $pdfResult['error'] = 'PDF generated but no URL found in response';
                            $pdfResult['raw_response'] = $pdfResponse;
                            $pdfResult['response_keys'] = array_keys($pdfResponse);
                        }
                    }
                }
            } else {
                $pdfResult['error'] = 'Invalid or empty PDF response from Universal Assistance service';
                $pdfResult['raw_response'] = $pdfResponse;
            }
        } catch (\Exception $e) {
            $pdfResult['error'] = 'PDF generation failed: ' . $e->getMessage();
        }

        return $pdfResult;
    }

    /**
     * Extract PDF data from SendReport response according to WSDL structure
     * WSDL Path: SM.ListOfUaSendReportIo.UaVoucherBc.ListOfUaImpresionSimplificadaBc.UaImpresionSimplificadaBc.ReportOutputFileBuffer
     */
    protected function extractPdfFromSendReportResponse(array $response): array
    {
        $result = [
            'success' => false,
            'pdf_data' => null,
            'pdf_url' => null,
            'file_name' => null,
            'file_ext' => null,
        ];

        try {
            // First, try to navigate through the expected WSDL structure
            $sm = $response['SM'] ?? null;
            if ($sm && isset($sm['ListOfUaSendReportIo'])) {
                $listOfUaSendReportIo = $sm['ListOfUaSendReportIo'];

                if (isset($listOfUaSendReportIo['UaVoucherBc']['ListOfUaImpresionSimplificadaBc']['UaImpresionSimplificadaBc'])) {
                    $uaImpresionSimplificadaBc = $listOfUaSendReportIo['UaVoucherBc']['ListOfUaImpresionSimplificadaBc']['UaImpresionSimplificadaBc'];

                    $pdfBuffer = $uaImpresionSimplificadaBc['ReportOutputFileBuffer'] ?? null;
                    $fileName = $uaImpresionSimplificadaBc['ReportOutputFileName'] ?? null;
                    $fileExt = $uaImpresionSimplificadaBc['ReportOutputFileExt'] ?? 'pdf';

                    if ($pdfBuffer) {
                        $result['success'] = true;
                        $result['pdf_data'] = $pdfBuffer;
                        $result['pdf_url'] = 'data:application/pdf;base64,' . $pdfBuffer;
                        $result['file_name'] = $fileName;
                        $result['file_ext'] = $fileExt;
                        return $result;
                    }
                }
            }

            // Alternative approach: Look for PDF data in different possible locations
            $possiblePdfFields = [
                'ReportOutputFileBuffer',
                'FileBuffer',
                'PDFData',
                'Data',
                'Buffer',
                'Content',
                'PDFContent',
                'ReportData'
            ];

            $possibleUrlFields = [
                'ReportOutputFileUrl',
                'FileUrl',
                'PDFUrl',
                'Url',
                'Link',
                'ReportUrl',
                'DownloadUrl'
            ];

            $possibleFileNameFields = [
                'ReportOutputFileName',
                'FileName',
                'Name',
                'ReportName'
            ];

            // Recursive search through the response for PDF data
            $this->searchForPdfInResponse($response, $possiblePdfFields, $possibleUrlFields, $possibleFileNameFields, $result);
        } catch (\Exception $e) {
            $result['extraction_error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Recursively search for PDF data in response
     */
    protected function searchForPdfInResponse(array $data, array $pdfFields, array $urlFields, array $fileNameFields, array &$result): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Recursively search nested arrays
                $this->searchForPdfInResponse($value, $pdfFields, $urlFields, $fileNameFields, $result);
            } elseif (is_string($value) && ! empty($value)) {
                // Check if this could be PDF data (base64)
                if (in_array($key, $pdfFields) && strlen($value) > 100 && $this->isPossibleBase64Pdf($value)) {
                    $result['success'] = true;
                    $result['pdf_data'] = $value;
                    $result['pdf_url'] = 'data:application/pdf;base64,' . $value;
                    return;
                }

                // Check if this could be a PDF URL
                if (in_array($key, $urlFields) && (strpos($value, 'http') === 0 || strpos($value, 'www.') !== false)) {
                    $result['success'] = true;
                    $result['pdf_url'] = $value;
                    return;
                }

                // Store potential file name
                if (in_array($key, $fileNameFields)) {
                    $result['file_name'] = $value;
                }
            }
        }
    }

    /**
     * Check if a string could be base64 encoded PDF data
     */
    protected function isPossibleBase64Pdf(string $data): bool
    {
        // Basic validation for base64 and reasonable PDF size
        if (strlen($data) < 100) {
            return false;
        }

        // Check if it's valid base64
        if (! preg_match('/^[a-zA-Z0-9\/\r\n+]*={0,2}$/', $data)) {
            return false;
        }

        // Try to decode and check for PDF signature
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            return false;
        }

        // PDF files start with %PDF
        return strpos($decoded, '%PDF') === 0;
    }

    /**
     * Find URLs in response recursively
     */
    protected function findUrlsInResponse(array $data, array &$result): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->findUrlsInResponse($value, $result);
            } elseif (is_string($value) && ! empty($value)) {
                if (strpos($value, 'http') === 0 || strpos($value, 'www.') !== false) {
                    $result['success'] = true;
                    $result['pdf_url'] = $value;
                    return;
                }
            }
        }
    }

    /**
     * Find base64 data in response recursively
     */
    protected function findBase64DataInResponse(array $data, array &$result): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->findBase64DataInResponse($value, $result);
            } elseif (is_string($value) && strlen($value) > 100) {
                if ($this->isPossibleBase64Pdf($value)) {
                    $result['success'] = true;
                    $result['pdf_data'] = $value;
                    $result['pdf_url'] = 'data:application/pdf;base64,' . $value;
                    return;
                }
            }
        }
    }

    /**
     * Build the Tarifa parameter for SendReport based on quotation data
     * The Tarifa parameter might need specific formatting for the BI Publisher service
     */
    protected function buildTarifaParameter(array $quoteData, array $personData): string
    {
        // Try different price fields from quotation data
        $tarifa = $quoteData['PrecioEmision'] ??
                 $quoteData['PrecioNeto'] ??
                 $quoteData['PrecioBruto'] ??
                 $quoteData['PrecioUnitario'] ??
                 $personData['plan']['price'] ??
                 '';

        // If we have a tarifa value, ensure it's properly formatted
        if ($tarifa !== '' && $tarifa !== null) {
            // Convert to string and ensure it has proper decimal formatting
            $tarifa = number_format((float)$tarifa, 2, '.', '');
        }

        // Some services might expect empty string instead of zero
        return (string)$tarifa;
    }

    /**
     * Extract variant type from person data (now includes variant injected from eSIM data)
     */
    protected function extractVariantType(array $personData): string
    {
        // 1. Try direct variantType field (injected from eSIM data)
        if (isset($personData['variantType'])) {
            $variantType = strtolower(trim($personData['variantType']));
            if (in_array($variantType, ['basic', 'unlimited'])) {
                return $variantType;
            }
        }

        // 2. Try variant_info attributes (fallback if still present)
        if (isset($personData['variant_info']['attributes']['Variant Type'])) {
            $variantType = strtolower(trim($personData['variant_info']['attributes']['Variant Type']));
            if (in_array($variantType, ['basic', 'unlimited'])) {
                return $variantType;
            }
        }

        // 3. Try eSimDetails variantType (fallback if still present)
        if (isset($personData['eSimDetails']['variantType'])) {
            $variantType = strtolower(trim($personData['eSimDetails']['variantType']));
            if (in_array($variantType, ['basic', 'unlimited'])) {
                return $variantType;
            }
        }

        // 4. Try plan variant/type fields (explicit only)
        $planVariant = strtolower($personData['plan']['variant'] ?? $personData['plan']['type'] ?? '');
        if (in_array($planVariant, ['basic', 'unlimited'])) {
            return $planVariant;
        }

        // 5. Try to get variant info from the order's eSIM data if available (last resort)
        if ($this->messageId) {
            $variantType = $this->getVariantTypeFromOrderESim();
            if ($variantType) {
                return $variantType;
            }
        }

        // 6. Default to basic if no variant type found
        return 'basic';
    }

    /**
     * Extract variant type from order's eSIM data
     */
    private function getVariantTypeFromOrderESim(): ?string
    {
        try {
            if (! $this->messageId) {
                return null;
            }

            $message = Message::getById($this->messageId, $this->app);
            $messageData = $message->message;

            // Check if it's an array of eSIMs
            if (isset($messageData['esims']) && is_array($messageData['esims'])) {
                foreach ($messageData['esims'] as $esim) {
                    if (is_array($esim)) {
                        // Try variant_info.attributes["Variant Type"]
                        if (isset($esim['variant_info']['attributes']['Variant Type'])) {
                            $variantType = strtolower(trim($esim['variant_info']['attributes']['Variant Type']));
                            if (in_array($variantType, ['basic', 'unlimited'])) {
                                return $variantType;
                            }
                        }

                        // Try eSimDetails.variantType
                        if (isset($esim['eSimDetails']['variantType'])) {
                            $variantType = strtolower(trim($esim['eSimDetails']['variantType']));
                            if (in_array($variantType, ['basic', 'unlimited'])) {
                                return $variantType;
                            }
                        }
                    }
                }
            }

            // Check single eSIM structure
            if (isset($messageData['variant_info']['attributes']['Variant Type'])) {
                $variantType = strtolower(trim($messageData['variant_info']['attributes']['Variant Type']));
                if (in_array($variantType, ['basic', 'unlimited'])) {
                    return $variantType;
                }
            }

            if (isset($messageData['eSimDetails']['variantType'])) {
                $variantType = strtolower(trim($messageData['eSimDetails']['variantType']));
                if (in_array($variantType, ['basic', 'unlimited'])) {
                    return $variantType;
                }
            }
        } catch (\Exception $e) {
            // Silently handle errors and fall back to default
        }

        return null;
    }

    /**
     * Perform dual quotation workflow - always get both inclusion and cross selling quotations
     * with variant-based convenio selection
     */
    protected function performDualQuotationWorkflow(array $personData, string $originCountryCode, string $destinationCountryCode): array
    {
        // Extract variant type from multiple possible sources
        $planVariant = $this->extractVariantType($personData);

        // Get target plan from the actual plan name in the data
        $targetPlan = $personData['plan']['name'] ?? '';

        // Determine convenios based on variant type
        if ($planVariant === 'basic') {
            // Basic → TELEASISTENCIA convenios
            $inclusionConvenio = '1-EO6M4QP';  // TELEASISTENCIA inclusion
            $crossSellingConvenio = '1-EO6M4QU'; // TELEASISTENCIA cross selling
        } else {
            // Unlimited → ASISTENCIA 10K REC convenios
            $inclusionConvenio = '1-EO7PJQQ';  // ASISTENCIA 10K REC inclusion
            $crossSellingConvenio = '1-EO7PJQL'; // ASISTENCIA 10K REC cross selling
        }

        // Perform inclusion quotation
        $inclusionResult = $this->performSingleQuotation($personData, $originCountryCode, $destinationCountryCode, 'inclusion', $inclusionConvenio);

        // Add delay between quotations
        usleep(5000);

        // Perform cross selling quotation
        $crossSellingResult = $this->performSingleQuotation($personData, $originCountryCode, $destinationCountryCode, 'cross_selling', $crossSellingConvenio);

        return [
            'inclusion' => [
                'type' => 'inclusion',
                'convenio' => $inclusionConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'result' => $inclusionResult
            ],
            'cross_selling' => [
                'type' => 'cross_selling',
                'convenio' => $crossSellingConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'result' => $crossSellingResult
            ],
            'timestamp' => now()->toISOString(),
            'selection_logic' => [
                'variant' => $planVariant,
                'target_plan' => $targetPlan,
                'inclusion_convenio' => $inclusionConvenio,
                'cross_selling_convenio' => $crossSellingConvenio
            ]
        ];
    }

    /**
     * Perform a single quotation with specific convenio
     */
    protected function performSingleQuotation(array $personData, string $originCountryCode, string $destinationCountryCode, string $quotationType, string $convenio): array
    {
        // Build voucher data for the quotation
        if ($quotationType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherDataWithConvenio($personData, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        } else {
            $voucherData = $this->buildVoucherDataWithConvenio($personData, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        }

        // Perform the quotation using the client
        try {
            $result = $this->client->createSingleQuotationWithCountries(
                $voucherData,
                $quotationType,
                $originCountryCode,
                $destinationCountryCode,
                $this->order,
                true // Only quotation, no voucher creation yet
            );

            // Convert to arrays
            $result = $this->convertObjectsToArrays($result);

            return [
                'success' => true,
                'quotation_data' => $result,
                'convenio' => $convenio,
                'quotation_type' => $quotationType
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'convenio' => $convenio,
                'quotation_type' => $quotationType
            ];
        }
    }

    /**
     * Select the best quotation for voucher creation - simplified approach
     */
    protected function selectBestQuotationForVoucher(array $personData, array $dualQuotationResult): array
    {
        // Get quotation results
        $inclusionResult = $dualQuotationResult['inclusion']['result'] ?? [];
        $crossSellingResult = $dualQuotationResult['cross_selling']['result'] ?? [];

        // Simple priority: cross_selling first, then inclusion
        // Just use whatever quotation is successful without complex matching
        if ($crossSellingResult['success'] ?? false) {
            return [
                'quotation_type' => 'cross_selling',
                'quotation_data' => $dualQuotationResult['cross_selling'],
                'selection_reason' => 'cross_selling_available',
                'convenio' => $dualQuotationResult['cross_selling']['convenio']
            ];
        }

        if ($inclusionResult['success'] ?? false) {
            return [
                'quotation_type' => 'inclusion',
                'quotation_data' => $dualQuotationResult['inclusion'],
                'selection_reason' => 'inclusion_available',
                'convenio' => $dualQuotationResult['inclusion']['convenio']
            ];
        }

        // Only return error if BOTH quotations failed completely
        return [
            'quotation_type' => 'error',
            'quotation_data' => null,
            'selection_reason' => 'no_successful_quotations',
            'convenio' => null,
            'errors' => [
                'inclusion' => $inclusionResult['error'] ?? 'Unknown error',
                'cross_selling' => $crossSellingResult['error'] ?? 'Unknown error'
            ]
        ];
    }

    /**
     * Create voucher from selected quotation with simplified process
     */
    protected function createVoucherFromSelectedQuotation(
        array $personData,
        array $selectedQuotation,
        string $originCountryCode,
        string $destinationCountryCode,
        string $personType
    ): array {
        // Only stop voucher creation if there are NO successful quotations available
        if ($selectedQuotation['quotation_type'] === 'error' && $selectedQuotation['selection_reason'] === 'no_successful_quotations') {
            return [
                'success' => false,
                'error' => 'No valid quotation available for voucher creation - both inclusion and cross_selling failed',
                'quotation_errors' => $selectedQuotation['errors'] ?? []
            ];
        }

        // Build voucher data using the specific convenio from selected quotation
        $convenio = $selectedQuotation['convenio'];
        if ($selectedQuotation['quotation_type'] === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherDataWithConvenio($personData, $personType, $originCountryCode, $destinationCountryCode, $convenio);
        } else {
            $voucherData = $this->buildVoucherDataWithConvenio($personData, $personType, $originCountryCode, $destinationCountryCode, $convenio);
        }

        // Always set LeadId as empty string as requested
        $voucherData['LeadId'] = '';

        // Extract precio de emision from the quotation data
        $precioEmision = '';
        if (isset($selectedQuotation['quotation_data']['result']['quotation_data'])) {
            $quoteData = $this->extractQuoteData($selectedQuotation['quotation_data']['result']['quotation_data']);
            $precioEmision = $quoteData['PrecioEmision'] ?? $quoteData['PrecioNeto'] ?? $quoteData['PrecioBruto'] ?? '';
        }

        // Set Tarifa and use precio de emision from quotation
        $voucherData['Tarifa'] = 'N';
        $voucherData['Precio'] = $precioEmision;

        // Use the plan name directly as requested - no matching needed
        if (isset($personData['plan']['name']) && ! empty($personData['plan']['name'])) {
            $voucherData['DatosProducto']['NombreProducto'] = $personData['plan']['name'];
        }

        // Add delay to ensure unique timestamps
        $delayMs = $personType === 'titular' ? 5000 : 10000;
        usleep($delayMs);

        try {
            // Create the actual voucher (not just quotation)
            $result = $this->client->createSingleQuotationWithCountries(
                $voucherData,
                $selectedQuotation['quotation_type'],
                $originCountryCode,
                $destinationCountryCode,
                $this->order,
                false // Create actual voucher
            );

            // Convert result to arrays and add metadata
            $result = $this->convertObjectsToArrays($result);
            $result['selected_quotation_metadata'] = $selectedQuotation;

            // Generate PDF if voucher was created successfully
            if (isset($result['voucher_response']) &&
                isset($result['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'])) {
                $pdfResult = $this->generateVoucherPDF($result, $personData);
                $result['pdf_data'] = $pdfResult;
            }

            return [
                'success' => true,
                'voucher_data' => $result,
                'convenio_used' => $convenio,
                'quotation_type_used' => $selectedQuotation['quotation_type']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Voucher creation failed: ' . $e->getMessage(),
                'convenio_attempted' => $convenio,
                'quotation_type_attempted' => $selectedQuotation['quotation_type']
            ];
        }
    }

    /**
     * Get the best available plan from quotation data (first available plan as fallback)
     */
    protected function getBestAvailablePlan(array $quoteDataArray): array
    {
        if (empty($quoteDataArray) || ! is_array($quoteDataArray)) {
            return ['found' => false, 'reason' => 'No quote data available'];
        }

        $quoteData = $quoteDataArray[0] ?? [];

        // Check if there's a main product in the quote
        if (isset($quoteData['NombreProducto']) && $quoteData['NombreProducto']) {
            return [
                'found' => true,
                'product_name' => $quoteData['NombreProducto'],
                'quote_data' => $quoteData,
                'match_type' => 'fallback_main_product'
            ];
        }

        // Check in attributes if available
        $attributes = $quoteData['Atributo'] ?? $quoteData['attributes'] ?? [];
        if (! empty($attributes) && is_array($attributes)) {
            $firstAttribute = $attributes[0] ?? [];
            $productName = $firstAttribute['NombreProducto'] ?? $firstAttribute['NombreVisible'] ?? null;

            if ($productName) {
                return [
                    'found' => true,
                    'product_name' => $productName,
                    'attribute_data' => $firstAttribute,
                    'quote_data' => $quoteData,
                    'match_type' => 'fallback_first_attribute'
                ];
            }
        }

        return ['found' => false, 'reason' => 'No product names found in quote data'];
    }

    /**
     * Find matching product in the quotation data structure (DatosLeadCotizadorOut)
     *
     * This method works with the real Universal Assistance quotation response structure:
     * - Main product info: NombreProducto (e.g., "DOM TELEASISTENCIA SIMLIMITES", "DOM ASISTENCIA 10K SIMLIMITES REC")
     * - Category info: Categoria (e.g., "Receptivos"), FamiliaProducto (e.g., "Teleasistencia", "Value 80")
     * - DatosLeadCotizadorOut can be either an object or an array of objects
     *
     * Matching logic focuses on NombreProducto:
     * - TELEASISTENCIA: looks for "teleasistencia" in NombreProducto
     * - ASISTENCIA 10K REC: looks for "asistencia" and "10k" in NombreProducto
     */
    protected function findMatchingProductInQuoteData(string $searchPlan, array $quoteData): array
    {
        if (empty($searchPlan)) {
            return [
                'found' => false,
                'reason' => 'Missing search plan'
            ];
        }

        $searchPlanLower = strtolower(trim($searchPlan));

        // Handle the case where DatosLeadCotizadorOut can be array or object
        $quotesToCheck = [];

        // Check if it's an array with numeric indices (multiple quotes)
        if (isset($quoteData[0])) {
            // DatosLeadCotizadorOut is an array of quote objects/arrays
            $quotesToCheck = $quoteData;
        } elseif (! empty($quoteData) && (isset($quoteData['NombreProducto']) || isset($quoteData['IdLeadOut']))) {
            // DatosLeadCotizadorOut is a single quote object/array
            $quotesToCheck = [$quoteData];
        } else {
            // Try to find quotes in nested structure
            foreach ($quoteData as $key => $value) {
                if (is_array($value) || is_object($value)) {
                    if (is_object($value)) {
                        $value = (array) $value;
                    }
                    if (isset($value['NombreProducto']) || isset($value['IdLeadOut'])) {
                        $quotesToCheck[] = $value;
                    }
                }
            }
        }

        foreach ($quotesToCheck as $index => $singleQuote) {
            if (empty($singleQuote)) {
                continue;
            }

            // Convert object to array for consistent handling
            if (is_object($singleQuote)) {
                $singleQuote = (array) $singleQuote;
            }

            $nombreProducto = $singleQuote['NombreProducto'] ?? '';
            if (! $nombreProducto) {
                continue;
            }

            $nombreProductoLower = strtolower(trim($nombreProducto));

            // 1. Try exact match first
            if ($nombreProductoLower === $searchPlanLower) {
                return [
                    'found' => true,
                    'product_name' => $nombreProducto,
                    'match_type' => 'exact_match',
                    'quote_data' => $singleQuote,
                    'quote_index' => $index
                ];
            }

            // 2. Try search plan contained in product name (for cases like "TELEASISTENCIA" in "DOM TELEASISTENCIA SIMLIMITES")
            if (strpos($nombreProductoLower, $searchPlanLower) !== false) {
                return [
                    'found' => true,
                    'product_name' => $nombreProducto,
                    'match_type' => 'partial_match_found',
                    'quote_data' => $singleQuote,
                    'quote_index' => $index
                ];
            }

            // 3. Try flexible matching for similar plans (e.g., "DOM MASTER 25K SIMLIMITES" matches "DOM MASTER 40K SIMLIMITES REC")
            $searchWords = explode(' ', $searchPlanLower);
            $productWords = explode(' ', $nombreProductoLower);

            // Check if key identifying words match (excluding numbers and common suffixes)
            $keyWords = [];
            $productKeyWords = [];

            foreach ($searchWords as $word) {
                // Keep important words, skip numbers and common suffixes
                // But keep 'simlimites' if it's in the original search plan
                if (! preg_match('/^\d+k?$/', $word) &&
                    ! in_array($word, ['rec', 'dom']) &&
                    ! ($word === 'simlimites' && strpos($searchPlanLower, 'simlimites') === false)) {
                    $keyWords[] = $word;
                }
            }

            foreach ($productWords as $word) {
                // Keep important words, skip numbers and common suffixes
                // But keep 'simlimites' if it's in the search plan
                if (! preg_match('/^\d+k?$/', $word) &&
                    ! in_array($word, ['rec', 'dom']) &&
                    ! ($word === 'simlimites' && strpos($searchPlanLower, 'simlimites') === false)) {
                    $productKeyWords[] = $word;
                }
            }

            // If key identifying words match, consider it a match
            if (! empty($keyWords) && ! empty($productKeyWords)) {
                $matchingWords = array_intersect($keyWords, $productKeyWords);
                if (count($matchingWords) >= count($keyWords)) {
                    return [
                        'found' => true,
                        'product_name' => $nombreProducto,
                        'match_type' => 'flexible_match',
                        'quote_data' => $singleQuote,
                        'quote_index' => $index,
                        'matched_words' => $matchingWords
                    ];
                }
            }
        }

        // NO MATCH FOUND - If no exact or partial match found, return false
        return [
            'found' => false,
            'reason' => 'No match found for plan: ' . $searchPlan,
            'searched_for' => $searchPlan,
            'available_products' => array_filter(array_map(function ($quote) {
                if (is_object($quote)) {
                    $quote = (array) $quote;
                }
                return $quote['NombreProducto'] ?? null;
            }, $quotesToCheck)),
            'quotes_checked' => count($quotesToCheck)
        ];
    }

    /**
     * Extract quote data from response structure, handling both object and array cases
     */
    protected function extractQuoteData(array $responseData): array
    {
        // Try different possible paths in the response structure
        $quoteData = $responseData['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                     $responseData['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                     $responseData['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                     [];

        return $quoteData;
    }

    /**
     * Extract IdLeadOut from quotation response.
     */
    protected function extractIdLeadOut($quotationResponse): ?string
    {
        // Handle direct IdLeadOut field
        if (isset($quotationResponse['IdLeadOut'])) {
            return $quotationResponse['IdLeadOut'];
        }

        // Check if it's in DatosLeadCotizadorOut
        if (isset($quotationResponse['DatosLeadCotizadorOut'])) {
            $quoteData = $quotationResponse['DatosLeadCotizadorOut'];

            // Handle array case - take first element
            if (is_array($quoteData) && ! empty($quoteData)) {
                $firstQuote = $quoteData[0];
                return is_array($firstQuote) ? ($firstQuote['IdLeadOut'] ?? null) : ($firstQuote->IdLeadOut ?? null);
            }

            // Handle object case
            if (is_object($quoteData)) {
                return $quoteData->IdLeadOut ?? null;
            }
        }

        // Check in nested quotation_data structure (for full responses)
        if (isset($quotationResponse['quotation_data']['DatosLeadCotizadorOut'])) {
            $quoteData = $quotationResponse['quotation_data']['DatosLeadCotizadorOut'];

            // Handle array case - take first element
            if (is_array($quoteData) && ! empty($quoteData)) {
                $firstQuote = $quoteData[0];
                return is_array($firstQuote) ? ($firstQuote['IdLeadOut'] ?? null) : ($firstQuote->IdLeadOut ?? null);
            }

            // Handle object case
            if (is_object($quoteData)) {
                return $quoteData->IdLeadOut ?? null;
            }
        }

        // Check in quote_response structure
        if (isset($quotationResponse['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'])) {
            $quoteData = $quotationResponse['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'];

            // Handle array case - take first element
            if (is_array($quoteData) && ! empty($quoteData)) {
                $firstQuote = $quoteData[0];
                return is_array($firstQuote) ? ($firstQuote['IdLeadOut'] ?? null) : ($firstQuote->IdLeadOut ?? null);
            }

            // Handle object case
            if (is_object($quoteData)) {
                return $quoteData->IdLeadOut ?? null;
            }
        }

        return null;
    }

    /**
     * Build voucher data with specific convenio (inclusion)
     */
    protected function buildVoucherDataWithConvenio(array $personData, string $personType, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // Get destination name using the proper mapping
        $destination = $this->getDestinationName($destinationCountryCode);
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates
        $activationDate = Carbon::parse($personData['activationDate']);
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration); // Duration days from activation date

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // Empty price for voucher creation as requested
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $convenio, // Use the specific convenio from variant logic
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N', // Campo "imprime tarifa" en "N" como solicitado
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7', // QA fallback
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'], // Use the actual plan name from cart data
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => $this->getDocumentType($personData['idType']),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => $this->getCountryName($originCountryCode),
                'SexoSolicitante' => 'M', // Default gender
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'TituloCortesiaSolicitante' => 'Sr.', // Default courtesy title
                'EdadSolicitante' => Carbon::parse($personData['dob'])->age,
            ],
        ];
    }

    /**
     * Build cross selling voucher data with specific convenio
     */
    protected function buildCrossSellingVoucherDataWithConvenio(array $personData, string $personType, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // Get destination name using the proper mapping
        $destination = $this->getDestinationName($destinationCountryCode);
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates
        $activationDate = Carbon::parse($personData['activationDate']);
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration); // Duration days from activation date

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination, // Use proper destination instead of 'Mundial'
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // Empty price for voucher creation as requested
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $convenio, // Use the specific convenio from variant logic
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7', // QA fallback
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'], // Use the actual plan name from cart data
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => $this->getDocumentType($personData['idType']),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => $this->getCountryName($originCountryCode),
                'SexoSolicitante' => 'M', // Default gender
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'TituloCortesiaSolicitante' => 'Sr.', // Default courtesy title
                'EdadSolicitante' => Carbon::parse($personData['dob'])->age,
            ],
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
}
