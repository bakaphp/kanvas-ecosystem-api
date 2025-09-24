<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\ContractEnum;
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

        // Extract titular's country information to use for all family members
        $titularOriginCountryCode = null;
        $titularDestinationCountryCode = null;

        // Process titular (main applicant)
        if (isset($insuranceData['titular'])) {
            // Extract country codes from titular data
            $titularOriginCountryCode = $insuranceData['titular']['originCountryCode'] ?? 'AR';
            $titularDestinationCountryCode = $insuranceData['titular']['destinationCountryCode'] ??
                                           $insuranceData['titular']['destinyCountryCode'] ?? 'DO';

            $results['titular'] = $this->processTitular($insuranceData['titular']);
        } else {
            throw new ValidationException('Titular data not found in insurance data');
        }

        // Process dependents using titular's country information
        if (isset($insuranceData['dependents']) && ! empty($insuranceData['dependents'])) {
            $results['dependents'] = [];
            foreach ($insuranceData['dependents'] as $dependent) {
                $results['dependents'][] = $this->processDependent($dependent, $titularOriginCountryCode, $titularDestinationCountryCode);
            }
        }

        return $results;
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
     * Process titular (main applicant) insurance
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

        // Determine plan type and create single voucher accordingly
        $planType = $this->determinePlanType($titularData);

        if ($planType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherData($titularData, 'titular', $originCountryCode, $destinationCountryCode);
        } else {
            // Default to inclusion if not cross_selling
            $voucherData = $this->buildVoucherData($titularData, 'titular', $originCountryCode, $destinationCountryCode);
        }

        // Add small delay to ensure unique timestamps for control numbers
        usleep(5000); // 5ms delay for titular
        $result = $this->client->createSingleQuotation($voucherData, $planType, $this->order, false);

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Extract quotation data for validation
        $quoteData = $result['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    $result['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    [];

        // Perform product matching and price validation
        $matchedProduct = $this->findMatchingProductInQuote($titularData['plan']['name'] ?? null, $quoteData);
        $productValidation = $this->validateProductMatch(
            $titularData['plan']['name'] ?? null,
            $matchedProduct['found'] ? $matchedProduct['product_name'] : ($quoteData['NombreProducto'] ?? null)
        );
        $priceValidation = $this->validatePricingWithMatchedProduct($titularData, $quoteData, $matchedProduct);

        // Add validation results to the response
        $result['matched_product'] = $matchedProduct;
        $result['product_validation'] = $productValidation;
        $result['price_validation'] = $priceValidation;

        // Generate PDF for the voucher if voucher was created successfully
        if (isset($result['voucher_response']) &&
            isset($result['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'])) {
            $pdfResult = $this->generateVoucherPDF($result, $titularData);
            $result['pdf_data'] = $pdfResult;
        }

        // Store titular voucher information in eSim message metadata
        $this->storeVoucherInESimMessageMetadata($titularData, $result, 'titular');

        return $result;
    }

    /**
     * Process dependent insurance
     * Each dependent gets their own voucher since they have individual plans to pay
     */
    protected function processDependent(array $dependentData, string $titularOriginCountryCode, string $titularDestinationCountryCode): array
    {
        // Validate dependent data structure
        if (! $this->validatePersonData($dependentData, 'dependent')) {
            throw new ValidationException('Invalid dependent data structure');
        }

        // Determine plan type and create individual voucher for dependent
        $planType = $this->determinePlanType($dependentData);

        if ($planType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherData($dependentData, 'dependent', $titularOriginCountryCode, $titularDestinationCountryCode);
        } else {
            // Default to inclusion if not cross_selling
            $voucherData = $this->buildVoucherData($dependentData, 'dependent', $titularOriginCountryCode, $titularDestinationCountryCode);
        }

        // Create individual voucher for this dependent
        // Add small delay to ensure unique timestamps for control numbers
        usleep(10000); // 10ms delay to ensure timestamp uniqueness
        $result = $this->client->createSingleQuotation($voucherData, $planType, $this->order, false);

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Extract quotation data for validation
        $quoteData = $result['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    $result['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    [];

        // Perform product matching and price validation
        $matchedProduct = $this->findMatchingProductInQuote($dependentData['plan']['name'] ?? null, $quoteData);
        $productValidation = $this->validateProductMatch(
            $dependentData['plan']['name'] ?? null,
            $matchedProduct['found'] ? $matchedProduct['product_name'] : ($quoteData['NombreProducto'] ?? null)
        );
        $priceValidation = $this->validatePricingWithMatchedProduct($dependentData, $quoteData, $matchedProduct);

        // Add validation results to the response
        $result['matched_product'] = $matchedProduct;
        $result['product_validation'] = $productValidation;
        $result['price_validation'] = $priceValidation;

        // Generate PDF for the voucher if voucher was created successfully
        if (isset($result['voucher_response']) &&
            isset($result['voucher_response']['UAAltaVoucheMinResponse']['DatosVoucherResp']['NroVoucher'])) {
            $pdfResult = $this->generateVoucherPDF($result, $dependentData);
            $result['pdf_data'] = $pdfResult;
        }

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
        $expirationDate->addDays($duration - 1); // -1 because the activation day counts

        // Use the new country-based convenio logic instead of ContractEnum
        $contract = $this->client->getConvenioForCountries($originCountryCode, $destinationCountryCode, 'inclusion');

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // UA Precio Actual Fijo - always 0.00 for voucher creation
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract, // Using country-based convenio logic
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N', // Campo "imprime tarifa" en "N" como solicitado

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
                'PaisResidenciaSolicitante' => $this->getCountryName($personData['originCountryCode'] ?? 'AR'),
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
        $expirationDate->addDays($duration - 1);

        // Use the new country-based convenio logic for Cross Selling
        $contract = $this->client->getConvenioForCountries($originCountryCode, $destinationCountryCode, 'cross_selling');

        return [
            'NroControl' => '', // Will be set by dual quotation system
            'Vendedor' => $this->app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO', // Using QA user as seller
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination, // Use proper destination instead of 'Mundial'
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00', // UA Precio Actual Fijo - always 0.00 for voucher creation
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract, // Using country-based convenio logic for Cross Selling
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',

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
                'PaisResidenciaSolicitante' => $this->getCountryName($personData['originCountryCode'] ?? 'AR'),
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
            'passport' => 'Passport',
            'dni' => 'DNI',
            'cedula' => 'DNI', // Map cedula to DNI
            'license' => 'DNI', // Map license to DNI as fallback
        ];

        return $types[$idType] ?? 'DNI'; // Default to DNI instead of PASAPORTE
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

        return $countries[$countryCode] ?? 'INTERNACIONAL';
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

        // Find the specific product that matches the requested plan
        $matchedProduct = $this->findMatchingProductInQuote($personData['plan']['name'] ?? null, $quoteData);

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
                'price_validation' => $this->validatePricingWithMatchedProduct($personData, $quoteData, $matchedProduct),
                'validation_timestamp' => now()->toISOString(),
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

        // Determine which price to use based on matched product
        $quotedPrice = null;
        $priceSource = 'not_found';

        if ($matchedProduct['found']) {
            if ($matchedProduct['source'] === 'main_product') {
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
                } else {
                    // Fallback to main product price if attribute doesn't have price
                    $quotedPrice = $quoteData['PrecioEmision'] ?? $quoteData['PrecioNeto'] ?? null;
                    $priceSource = 'main_product_fallback';
                }
            }
        } else {
            // No product match, use main quote price
            $quotedPrice = $quoteData['PrecioEmision'] ?? $quoteData['PrecioNeto'] ?? null;
            $priceSource = 'main_product_no_match';
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

        // Add notes about product matching
        if ($matchedProduct['found']) {
            $validation['validation_notes'][] = "Price extracted from {$priceSource} for matched product: {$matchedProduct['product_name']}";
        } else {
            $validation['validation_notes'][] = 'Product not matched, using fallback pricing';
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

            // Try alternative approaches if Tarifa is empty - some reports might work without it
            if (empty($reportData['Tarifa'])) {
                // Try to get price from voucher response directly
                $alternativePrice = $voucherResult['voucher_response']['Precio'] ??
                                  $voucherResult['voucher_response']['PrecioTotal'] ??
                                  $voucherResult['voucher_response']['Amount'] ??
                                  '0.00';

                if ($alternativePrice && is_numeric($alternativePrice)) {
                    $reportData['Tarifa'] = number_format((float)$alternativePrice, 2, '.', '');
                }
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
