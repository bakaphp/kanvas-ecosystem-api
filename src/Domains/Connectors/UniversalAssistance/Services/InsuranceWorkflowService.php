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

        // Process titular (main applicant)
        if (isset($insuranceData['titular'])) {
            $results['titular'] = $this->processTitular($insuranceData['titular']);
        } else {
            throw new ValidationException('Titular data not found in insurance data');
        }

        // Process dependents
        if (isset($insuranceData['dependents']) && ! empty($insuranceData['dependents'])) {
            $results['dependents'] = [];
            foreach ($insuranceData['dependents'] as $dependent) {
                $results['dependents'][] = $this->processDependent($dependent);
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

        // Determine plan type and create single voucher accordingly
        $planType = $this->determinePlanType($titularData);

        if ($planType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherData($titularData, 'titular');
        } else {
            // Default to inclusion if not cross_selling
            $voucherData = $this->buildVoucherData($titularData, 'titular');
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
    protected function processDependent(array $dependentData): array
    {
        // Validate dependent data structure
        if (! $this->validatePersonData($dependentData, 'dependent')) {
            throw new ValidationException('Invalid dependent data structure');
        }

        // Determine plan type and create individual voucher for dependent
        $planType = $this->determinePlanType($dependentData);

        if ($planType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherData($dependentData, 'dependent');
        } else {
            // Default to inclusion if not cross_selling
            $voucherData = $this->buildVoucherData($dependentData, 'dependent');
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
    protected function buildVoucherData(array $personData, string $personType): array
    {
        // Convert destinationCountryCode to destination name (based on real input structure)
        $destination = $this->getDestinationName($personData['destinationCountryCode'] ?? $personData['destinyCountryCode'] ?? 'DO');

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

        // Get contract using enum
        $contract = ContractEnum::getContract('inclusion', $destination);

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
            'Contrato' => $contract->value, // Using enum for contract logic
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
    protected function buildCrossSellingVoucherData(array $personData, string $personType): array
    {
        // Convert destinationCountryCode to destination name (based on real input structure)
        $destination = $this->getDestinationName($personData['destinationCountryCode'] ?? $personData['destinyCountryCode'] ?? 'DO');

        // Validate destination
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates
        $activationDate = Carbon::parse($personData['activationDate']);
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration - 1);

        // Get contract using enum for Cross Selling
        $contract = ContractEnum::getContract('cross_selling', $destination);

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
            'Contrato' => $contract->value, // Using enum for Cross Selling contract
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

        if (! isset($messageData['universal_assistance'])) {
            $messageData['universal_assistance'] = [];
        }

        if (! isset($messageData['universal_assistance']['vouchers'])) {
            $messageData['universal_assistance']['vouchers'] = [];
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

        $messageData['universal_assistance']['vouchers'][] = $voucherInfo;

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

        if (! isset($messageData['universal_assistance'])) {
            $messageData['universal_assistance'] = [];
        }

        if (! isset($messageData['universal_assistance']['dependents'])) {
            $messageData['universal_assistance']['dependents'] = [];
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

        $messageData['universal_assistance']['dependents'][] = $dependentInfo;

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

            if (!$voucherNumber) {
                $pdfResult['error'] = 'No voucher number (NroVoucher) found to generate PDF';
                return $pdfResult;
            }

            // Extract quotation data to get the tarifa (pricing information)
            $quoteData = $voucherResult['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                        $voucherResult['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                        [];

            // Build report data for PDF generation using the NroVoucher
            $reportData = [
                'Language' => 'Spanish',  // Default to Spanish
                'VoucherNumber' => $voucherNumber, // This should be the NroVoucher (e.g., T417502009)
                'Tarifa' => '',
                'Organization' => $voucherResult['organization'] ?? '1-ENYNUF7'
            ];

            // Generate PDF using the sendReport method
            $pdfResponse = $this->client->sendReport($reportData, false);

            if (is_array($pdfResponse) && !empty($pdfResponse)) {
                // Check if PDF was generated successfully
                // The response typically contains a URL or base64 data
                if (isset($pdfResponse['PDFUrl']) || isset($pdfResponse['Url']) || isset($pdfResponse['Link'])) {
                    $pdfResult['success'] = true;
                    $pdfResult['pdf_url'] = $pdfResponse['PDFUrl'] ?? $pdfResponse['Url'] ?? $pdfResponse['Link'];
                } elseif (isset($pdfResponse['PDFData']) || isset($pdfResponse['Data'])) {
                    $pdfResult['success'] = true;
                    $pdfResult['pdf_data'] = $pdfResponse['PDFData'] ?? $pdfResponse['Data'];
                    $pdfResult['pdf_url'] = 'data:application/pdf;base64,' . ($pdfResponse['PDFData'] ?? $pdfResponse['Data']);
                } else {
                    // Check for any URL-like field in the response
                    foreach ($pdfResponse as $key => $value) {
                        if (is_string($value) && (strpos($value, 'http') === 0 || strpos($value, 'www.') !== false)) {
                            $pdfResult['success'] = true;
                            $pdfResult['pdf_url'] = $value;
                            break;
                        }
                    }

                    if (!$pdfResult['success']) {
                        $pdfResult['error'] = 'PDF generated but no URL found in response';
                        $pdfResult['raw_response'] = $pdfResponse;
                    }
                }
            } else {
                $pdfResult['error'] = 'Invalid or empty PDF response';
                $pdfResult['raw_response'] = $pdfResponse;
            }

        } catch (\Exception $e) {
            $pdfResult['error'] = 'PDF generation failed: ' . $e->getMessage();
        }

        return $pdfResult;
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
