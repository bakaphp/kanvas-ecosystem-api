<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
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

            // Pass variant data if available (for duration calculation)
            if (isset($insuranceData['titular']['variant'])) {
                $titularDataWithVariant['variant'] = $insuranceData['titular']['variant'];
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

                // Pass variant data if available (for duration calculation)
                if (isset($dependent['variant'])) {
                    $dependentDataWithVariant['variant'] = $dependent['variant'];
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
    public function processTitular(array $titularData): array
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
        $voucherResult = $this->createVoucherFromSelectedQuotation($titularData, $selectedQuotation, $originCountryCode, $destinationCountryCode, 'titular', $dualQuotationResult);

        // Create simplified result with complete quotation data and product matching information
        $result = [
            'dual_quotation_results' => $this->simplifyDualQuotationResults($dualQuotationResult),
            'selected_quotation' => $selectedQuotation,
            'voucher_result' => $voucherResult,
            'workflow_type' => 'dual_quotation_with_plan_matching',
            'person_type' => 'titular',
            'input_plan_requested' => $titularData['plan']['name'] ?? 'Unknown plan',
            'selected_product_match' => $voucherResult['matched_product'] ?? null,
            'quotation_summary' => [
                'origin_country' => $originCountryCode,
                'destination_country' => $destinationCountryCode,
                'variant_type' => $this->extractVariantType($titularData),
                'duration_days' => $this->getProductDuration($titularData),
                'quotation_timestamp' => now()->toISOString(),
            ],
        ];

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Store titular voucher information in eSim message metadata
        // NOTE: Disabled - ProcessInsuranceCartActivity handles message storage in correct format
        // $this->storeVoucherInESimMessageMetadata($titularData, $result, 'titular');

        return $result;
    }

    /**
     * Process dependent insurance with dual quotation workflow
     * Each dependent gets their own voucher since they have individual plans to pay
     */
    public function processDependent(array $dependentData, string $titularOriginCountryCode, string $titularDestinationCountryCode): array
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
        $voucherResult = $this->createVoucherFromSelectedQuotation($dependentData, $selectedQuotation, $titularOriginCountryCode, $titularDestinationCountryCode, 'dependent', $dualQuotationResult);

        // Create simplified result with complete quotation data and product matching information
        $result = [
            'dual_quotation_results' => $this->simplifyDualQuotationResults($dualQuotationResult),
            'selected_quotation' => $selectedQuotation,
            'voucher_result' => $voucherResult,
            'workflow_type' => 'dual_quotation_with_plan_matching',
            'person_type' => 'dependent',
            'input_plan_requested' => $dependentData['plan']['name'] ?? 'Unknown plan',
            'selected_product_match' => $voucherResult['matched_product'] ?? null,
            'quotation_summary' => [
                'origin_country' => $titularOriginCountryCode,
                'destination_country' => $titularDestinationCountryCode,
                'variant_type' => $this->extractVariantType($dependentData),
                'duration_days' => $this->getProductDuration($dependentData),
                'quotation_timestamp' => now()->toISOString(),
            ],
        ];

        // Convert result to arrays to prevent stdClass errors
        $result = $this->convertObjectsToArrays($result);

        // Store dependent voucher information in eSim message metadata
        // NOTE: Disabled - ProcessInsuranceCartActivity handles message storage in correct format
        // $this->storeVoucherInESimMessageMetadata($dependentData, $result, 'dependent');

        return $result;
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
            'Territorio Nacional',
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
            // Americas
            'AR' => 'ARGENTINA',
            'BO' => 'BOLIVIA',
            'BR' => 'BRASIL',
            'CA' => 'CANADA',
            'CL' => 'CHILE',
            'CO' => 'COLOMBIA',
            'CR' => 'COSTA RICA',
            'CU' => 'CUBA',
            'DO' => 'REPUBLICA DOMINICANA',
            'EC' => 'ECUADOR',
            'SV' => 'EL SALVADOR',
            'GT' => 'GUATEMALA',
            'GY' => 'GUYANA',
            'HT' => 'HAITI',
            'HN' => 'HONDURAS',
            'JM' => 'JAMAICA',
            'MX' => 'MEXICO',
            'NI' => 'NICARAGUA',
            'PA' => 'PANAMA',
            'PY' => 'PARAGUAY',
            'PE' => 'PERU',
            'PR' => 'PUERTO RICO',
            'SR' => 'SURINAM',
            'TT' => 'TRINIDAD Y TOBAGO',
            'US' => 'USA',
            'UY' => 'URUGUAY',
            'VE' => 'VENEZUELA',

            // Europe
            'AD' => 'ANDORRA',
            'AT' => 'AUSTRIA',
            'BE' => 'BELGICA',
            'BG' => 'BULGARIA',
            'HR' => 'CROACIA',
            'CY' => 'CHIPRE',
            'CZ' => 'REPUBLICA CHECA',
            'DK' => 'DINAMARCA',
            'EE' => 'ESTONIA',
            'FI' => 'FINLANDIA',
            'FR' => 'FRANCIA',
            'DE' => 'ALEMANIA',
            'GR' => 'GRECIA',
            'HU' => 'HUNGRIA',
            'IS' => 'ISLANDIA',
            'IE' => 'IRLANDA',
            'IT' => 'ITALIA',
            'LV' => 'LETONIA',
            'LI' => 'LIECHTENSTEIN',
            'LT' => 'LITUANIA',
            'LU' => 'LUXEMBURGO',
            'MT' => 'MALTA',
            'MC' => 'MONACO',
            'ME' => 'MONTENEGRO',
            'NL' => 'HOLANDA',
            'NO' => 'NORUEGA',
            'PL' => 'POLONIA',
            'PT' => 'PORTUGAL',
            'RO' => 'RUMANIA',
            'RU' => 'RUSIA',
            'SM' => 'SAN MARINO',
            'RS' => 'SERBIA',
            'SK' => 'ESLOVAQUIA',
            'SI' => 'ESLOVENIA',
            'ES' => 'ESPAÑA',
            'SE' => 'SUECIA',
            'CH' => 'SUIZA',
            'UA' => 'UCRANIA',
            'GB' => 'INGLATERRA',
            'VA' => 'CIUDAD VATICANO',

            // Asia
            'AF' => 'AFGHANISTAN',
            'AM' => 'ARMENIA',
            'AZ' => 'AZERBAIJAN',
            'BH' => 'BAHREIN',
            'BD' => 'BANGLADESH',
            'BT' => 'BHUTAN',
            'BN' => 'BRUNEI',
            'KH' => 'CAMBOYA',
            'CN' => 'CHINA',
            'GE' => 'GEORGIA',
            'IN' => 'INDIA',
            'ID' => 'INDONESIA',
            'IR' => 'IRAN',
            'IQ' => 'IRAK',
            'IL' => 'ISRAEL',
            'JP' => 'JAPON',
            'JO' => 'JORDANIA',
            'KZ' => 'KAZAJISTAN',
            'KP' => 'COREA DEL NORTE',
            'KR' => 'COREA DEL SUR',
            'KW' => 'KUWAIT',
            'KG' => 'KIRGUISTAN',
            'LA' => 'LAOS',
            'LB' => 'LIBANO',
            'MY' => 'MALASIA',
            'MV' => 'MALDIVAS',
            'MN' => 'MONGOLIA',
            'MM' => 'MYANMAR',
            'NP' => 'NEPAL',
            'OM' => 'OMAN',
            'PK' => 'PAKISTAN',
            'PS' => 'PALESTINA',
            'PH' => 'FILIPINAS',
            'QA' => 'QATAR',
            'SA' => 'ARABIA SAUDITA',
            'SG' => 'SINGAPUR',
            'LK' => 'SRI LANKA',
            'SY' => 'SIRIA',
            'TW' => 'TAIWAN',
            'TJ' => 'TAYIKISTAN',
            'TH' => 'TAILANDIA',
            'TL' => 'TIMOR ORIENTAL',
            'TR' => 'TURQUIA',
            'TM' => 'TURKMENISTAN',
            'AE' => 'EMIRATOS ARABES UNIDOS',
            'UZ' => 'UZBEKISTAN',
            'VN' => 'VIETNAM',
            'YE' => 'YEMEN',

            // Africa
            'DZ' => 'ARGELIA',
            'AO' => 'ANGOLA',
            'BJ' => 'BENIN',
            'BW' => 'BOTSWANA',
            'BF' => 'BURKINA FASO',
            'BI' => 'BURUNDI',
            'CV' => 'CABO VERDE',
            'CM' => 'CAMERUN',
            'CF' => 'REPUBLICA CENTROAFRICANA',
            'TD' => 'CHAD',
            'KM' => 'COMORAS',
            'CG' => 'CONGO',
            'CI' => 'COSTA DE MARFIL',
            'DJ' => 'YIBUTI',
            'EG' => 'EGIPTO',
            'GQ' => 'GUINEA ECUATORIAL',
            'ER' => 'ERITREA',
            'ET' => 'ETIOPIA',
            'GA' => 'GABON',
            'GM' => 'GAMBIA',
            'GH' => 'GHANA',
            'GN' => 'GUINEA',
            'GW' => 'GUINEA BISSAU',
            'KE' => 'KENIA',
            'LS' => 'LESOTO',
            'LR' => 'LIBERIA',
            'LY' => 'LIBIA',
            'MG' => 'MADAGASCAR',
            'MW' => 'MALAWI',
            'ML' => 'MALI',
            'MR' => 'MAURITANIA',
            'MU' => 'MAURICIO',
            'MA' => 'MARRUECOS',
            'MZ' => 'MOZAMBIQUE',
            'NA' => 'NAMIBIA',
            'NE' => 'NIGER',
            'NG' => 'NIGERIA',
            'RW' => 'RUANDA',
            'ST' => 'SANTO TOME Y PRINCIPE',
            'SN' => 'SENEGAL',
            'SC' => 'SEYCHELLES',
            'SL' => 'SIERRA LEONA',
            'SO' => 'SOMALIA',
            'ZA' => 'SUDAFRICA',
            'SS' => 'SUDAN DEL SUR',
            'SD' => 'SUDAN',
            'SZ' => 'SUAZILANDIA',
            'TZ' => 'TANZANIA',
            'TG' => 'TOGO',
            'TN' => 'TUNEZ',
            'UG' => 'UGANDA',
            'ZM' => 'ZAMBIA',
            'ZW' => 'ZIMBABWE',

            // Oceania
            'AU' => 'AUSTRALIA',
            'FJ' => 'FIYI',
            'KI' => 'KIRIBATI',
            'MH' => 'ISLAS MARSHALL',
            'FM' => 'MICRONESIA',
            'NR' => 'NAURU',
            'NZ' => 'NUEVA ZELANDA',
            'PW' => 'PALAOS',
            'PG' => 'PAPUA NUEVA GUINEA',
            'WS' => 'SAMOA',
            'SB' => 'ISLAS SALOMON',
            'TO' => 'TONGA',
            'TV' => 'TUVALU',
            'VU' => 'VANUATU',

            // Caribbean/Other
            'AI' => 'ANGUILA',
            'AG' => 'ANTIGUA Y BARBUDA',
            'AW' => 'ARUBA',
            'BS' => 'BAHAMAS',
            'BB' => 'BARBADOS',
            'BZ' => 'BELICE',
            'BM' => 'BERMUDAS',
            'VG' => 'ISLAS VIRGENES BRITANICAS',
            'KY' => 'ISLAS CAIMAN',
            'CW' => 'CURAZAO',
            'DM' => 'DOMINICA',
            'GD' => 'GRANADA',
            'GP' => 'GUADALUPE',
            'MQ' => 'MARTINICA',
            'MS' => 'ISLA DE MONTSERRAT',
            'AN' => 'ANTILLAS HOLANDESAS',
            'KN' => 'SAN CRISTOBAL Y NIEVES',
            'LC' => 'SANTA LUCIA',
            'VC' => 'SAN VICENTE Y GRANADINAS',
            'TC' => 'ISLAS TURCAS Y CAICOS',
        ];

        return $countries[$countryCode] ?? 'ARGENTINA'; // Default to a valid country
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
     * Search for the SPECIFIC duration that corresponds to the current plan/eSIM,
     * not just the first one found
     */
    protected function getProductDuration(array $personData): int
    {
        // PRIORITY 1: Try Universal Assistance plan duration (most reliable for UA insurance)
        if (isset($personData['plan']['duration'])) {
            $planDuration = (int) $personData['plan']['duration'];
            if ($planDuration > 0) {
                return $planDuration;
            }
        }

        // PRIORITY 2: Try variant_info attributes from eSIM data (passed from ProcessInsuranceCartActivity)
        if (isset($personData['variant_info']['attributes']['Variant Duration'])) {
            $variantDuration = (int) $personData['variant_info']['attributes']['Variant Duration'];
            if ($variantDuration > 0) {
                return $variantDuration;
            }
        }

        // DEFAULT: Return 7 days as fallback
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
                'note' => 'Price validation skipped - voucher has empty price by design',
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
                'product_quoted' => $productQuoted,
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
                'product_quoted' => $productQuoted,
            ];
        }

        // Check if plan name is contained in product name
        if (strpos($productNormalized, $planNormalized) !== false) {
            return [
                'match' => true,
                'match_type' => 'partial_plan_in_product',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted,
            ];
        }

        // Check if product name is contained in plan name
        if (strpos($planNormalized, $productNormalized) !== false) {
            return [
                'match' => true,
                'match_type' => 'partial_product_in_plan',
                'plan_requested' => $planRequested,
                'product_quoted' => $productQuoted,
            ];
        }

        // No match found
        return [
            'match' => false,
            'reason' => 'No sufficient similarity found',
            'plan_requested' => $planRequested,
            'product_quoted' => $productQuoted,
        ];
    }

    /**
     * Find the specific product in the quote response that matches the requested plan
     */
    protected function findMatchingProductInQuote(?string $requestedPlanName, array $quoteData): array
    {
        if (! $requestedPlanName || empty($quoteData)) {
            return [
                'found' => false,
                'reason' => 'Missing plan name or quote data',
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
                    'quote_data' => $quoteData,
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
                            'quote_data' => $quoteData,
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
                            'match_score' => $bestScore,
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
            'main_product' => $mainProductName,
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

        // Determine convenios and quotation types based on variant type
        if ($planVariant === 'basic') {
            // Basic → TELEASISTENCIA convenios (type I)
            $inclusionType = 'inclusion';
            $crossSellingType = 'cross_selling';
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_I->value);
        } else {
            // Unlimited → ASISTENCIA 10K REC convenios (type II)
            $inclusionType = 'inclusion_ii';
            $crossSellingType = 'cross_selling_ii';
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_II->value);
        }

        // Perform inclusion quotation with correct type
        $inclusionResult = $this->performSingleQuotation($personData, $originCountryCode, $destinationCountryCode, $inclusionType, $inclusionConvenio);

        // Add delay between quotations
        usleep(5000);

        // Perform cross selling quotation with correct type
        $crossSellingResult = $this->performSingleQuotation($personData, $originCountryCode, $destinationCountryCode, $crossSellingType, $crossSellingConvenio);

        return [
            'inclusion' => [
                'type' => $inclusionType,
                'convenio' => $inclusionConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'result' => $inclusionResult,
            ],
            'cross_selling' => [
                'type' => $crossSellingType,
                'convenio' => $crossSellingConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'result' => $crossSellingResult,
            ],
            'timestamp' => now()->toISOString(),
            'selection_logic' => [
                'variant' => $planVariant,
                'target_plan' => $targetPlan,
                'origin_country_code' => $originCountryCode,
                'convenio_logic' => strtoupper($originCountryCode) === 'DO' ? 'DO_origin_forced_basic' : 'variant_based',
                'inclusion_type' => $inclusionType,
                'cross_selling_type' => $crossSellingType,
                'inclusion_convenio' => $inclusionConvenio,
                'cross_selling_convenio' => $crossSellingConvenio,
            ],
        ];
    }

    /**
     * Perform a single quotation with specific convenio
     */
    protected function performSingleQuotation(array $personData, string $originCountryCode, string $destinationCountryCode, string $quotationType, string $convenio): array
    {
        // Build quotation data with ages calculated - this ensures individual quotations
        // have the same structure as group quotations with proper age information
        $quotationData = $this->buildIndividualQuotationData($personData, $originCountryCode, $destinationCountryCode, $convenio);

        // Build voucher data for voucher creation (will be used later if needed)
        if (in_array($quotationType, ['cross_selling', 'cross_selling_ii'])) {
            $voucherData = $this->buildCrossSellingVoucherDataWithConvenio($personData, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        } else {
            $voucherData = $this->buildVoucherDataWithConvenio($personData, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        }

        // Perform the quotation using the client with quotation data that includes ages
        try {
            $result = $this->client->createSingleQuotationWithCountries(
                $quotationData,
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
                'quotation_type' => $quotationType,
                'quotation_request_input' => $quotationData,  // Include the quotation request data with ages
                'voucher_request_input' => $voucherData,  // Include voucher data for later voucher creation
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'convenio' => $convenio,
                'quotation_type' => $quotationType,
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
        // Return only essential data without duplicating the full quotation responses
        if ($crossSellingResult['success'] ?? false) {
            return [
                'type' => 'cross_selling',
                //'convenio' => $dualQuotationResult['cross_selling']['convenio'] ?? '',
                'target_plan' => $dualQuotationResult['cross_selling']['target_plan'] ?? '',
                'variant' => $dualQuotationResult['cross_selling']['variant'] ?? '',
                //'group_size' => $dualQuotationResult['cross_selling']['group_size'] ?? 1,
                'result' => [
                    'success' => true,
                    'quotation_data' => [
                        'quotation_type' => 'cross_selling',
                        'control_number' => $crossSellingResult['quotation_data']['control_number'] ?? '',
                        'organization' => $crossSellingResult['quotation_data']['organization'] ?? '',
                        'convenio' => $crossSellingResult['quotation_data']['convenio'] ?? '',
                        'origin_country_code' => $crossSellingResult['quotation_data']['origin_country_code'] ?? '',
                        'destination_country_code' => $crossSellingResult['quotation_data']['destination_country_code'] ?? '',
                        'origin_country_name' => $crossSellingResult['quotation_data']['origin_country_name'] ?? '',
                        'destination_name' => $crossSellingResult['quotation_data']['destination_name'] ?? '',
                    ],
                ],
                'convenio' => $dualQuotationResult['cross_selling']['convenio'] ?? '',
                'quotation_type' => 'cross_selling',
                'group_size' => $dualQuotationResult['cross_selling']['group_size'] ?? 1,
            ];
        }

        if ($inclusionResult['success'] ?? false) {
            return [
                'type' => 'inclusion',
                //'convenio' => $dualQuotationResult['inclusion']['convenio'] ?? '',
                'target_plan' => $dualQuotationResult['inclusion']['target_plan'] ?? '',
                'variant' => $dualQuotationResult['inclusion']['variant'] ?? '',
                //'group_size' => $dualQuotationResult['inclusion']['group_size'] ?? 1,
                'result' => [
                    'success' => true,
                    'quotation_data' => [
                        'quotation_type' => 'inclusion',
                        'control_number' => $inclusionResult['quotation_data']['control_number'] ?? '',
                        'organization' => $inclusionResult['quotation_data']['organization'] ?? '',
                        'convenio' => $inclusionResult['quotation_data']['convenio'] ?? '',
                        'origin_country_code' => $inclusionResult['quotation_data']['origin_country_code'] ?? '',
                        'destination_country_code' => $inclusionResult['quotation_data']['destination_country_code'] ?? '',
                        'origin_country_name' => $inclusionResult['quotation_data']['origin_country_name'] ?? '',
                        'destination_name' => $inclusionResult['quotation_data']['destination_name'] ?? '',
                    ],
                ],
                'convenio' => $dualQuotationResult['inclusion']['convenio'] ?? '',
                'quotation_type' => 'inclusion',
                'group_size' => $dualQuotationResult['inclusion']['group_size'] ?? 1,
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
                'cross_selling' => $crossSellingResult['error'] ?? 'Unknown error',
            ],
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
        string $personType,
        array $dualQuotationResult = []
    ): array {
        // Only stop voucher creation if there are NO successful quotations available
        if (($selectedQuotation['quotation_type'] ?? '') === 'error' && ($selectedQuotation['selection_reason'] ?? '') === 'no_successful_quotations') {
            return [
                'success' => false,
                'error' => 'No valid quotation available for voucher creation - both inclusion and cross_selling failed',
                'quotation_errors' => $selectedQuotation['errors'] ?? [],
            ];
        }

        // FIRST: Find the exact plan/product that matches what was requested
        // Search in BOTH inclusion and cross_selling quotations to find the product
        $targetPlanName = $personData['plan']['name'] ?? '';
        $matchedProduct = null;
        $exactPrecioEmision = '';
        $exactConvenio = '';

        try {
            // Get both quotation results from dual quotation
            $inclusionQuotationData = $dualQuotationResult['inclusion']['result'] ?? null;
            $crossSellingQuotationData = $dualQuotationResult['cross_selling']['result'] ?? null;

            // Search in inclusion quotation first
            if ($inclusionQuotationData && ($inclusionQuotationData['success'] ?? false)) {
                $inclusionPaths = [
                    $inclusionQuotationData['quotation_data']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                    $inclusionQuotationData['quotation_data']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                    $inclusionQuotationData['quotation_data']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                ];

                foreach ($inclusionPaths as $datosLeadOut) {
                    if ($datosLeadOut !== null) {
                        $inclusionMatch = $this->findMatchingProductInQuoteData($targetPlanName, is_array($datosLeadOut) ? $datosLeadOut : [$datosLeadOut]);

                        if ($inclusionMatch && ($inclusionMatch['found'] ?? false)) {
                            $matchedProduct = $inclusionMatch;
                            $matchedProduct['source_quotation'] = 'inclusion';
                            $matchedProduct['source_convenio'] = $dualQuotationResult['inclusion']['convenio'] ?? '';

                            break; // Exit foreach loop - found in inclusion
                        }
                    }
                }
            }

            // If not found in inclusion, search in cross_selling quotation
            if ((! $matchedProduct || ! ($matchedProduct['found'] ?? false)) && $crossSellingQuotationData && ($crossSellingQuotationData['success'] ?? false)) {
                $crossSellingPaths = [
                    $crossSellingQuotationData['quotation_data']['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                    $crossSellingQuotationData['quotation_data']['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                    $crossSellingQuotationData['quotation_data']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
                ];

                foreach ($crossSellingPaths as $datosLeadOut) {
                    if ($datosLeadOut !== null) {
                        $crossSellingMatch = $this->findMatchingProductInQuoteData($targetPlanName, is_array($datosLeadOut) ? $datosLeadOut : [$datosLeadOut]);

                        if ($crossSellingMatch && ($crossSellingMatch['found'] ?? false)) {
                            $matchedProduct = $crossSellingMatch;
                            $matchedProduct['source_quotation'] = 'cross_selling';
                            $matchedProduct['source_convenio'] = $dualQuotationResult['cross_selling']['convenio'] ?? '';

                            break; // Found in cross_selling
                        }
                    }
                }
            }            if ($matchedProduct && ($matchedProduct['found'] ?? false)) {
                $productData = $matchedProduct['quote_data'] ?? [];

                // Extract EXACT price from the matched product
                $exactPrecioEmision = $productData['PrecioEmision'] ?? $productData['PrecioNeto'] ?? $productData['PrecioBruto'] ?? '';

                // Use the convenio from the quotation where the product was found
                $exactConvenio = $matchedProduct['source_convenio'] ?? '';
            }
        } catch (\Exception $e) {
            // Handle errors silently
        }

        // If no exact convenio found in product data, fall back to quotation convenio
        if (empty($exactConvenio)) {
            $exactConvenio = $this->extractConvenioWithFallback($selectedQuotation, $personData);
        }

        // Build voucher data using the EXACT convenio from the matched product location
        $actualQuotationType = $matchedProduct['source_quotation'] ?? ($selectedQuotation['quotation_type'] ?? 'inclusion');

        if ($actualQuotationType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherDataWithConvenio($personData, $personType, $originCountryCode, $destinationCountryCode, $exactConvenio);
        } else {
            $voucherData = $this->buildVoucherDataWithConvenio($personData, $personType, $originCountryCode, $destinationCountryCode, $exactConvenio);
        }

        // Always set LeadId as empty string as requested
        $voucherData['LeadId'] = '';

        // CRITICAL: Extract IdLeadOut from the selected quotation to pass to voucher (for individual vouchers)
        $idLeadOut = null;

        // Try to extract IdLeadOut from the matched product first
        if ($matchedProduct && ($matchedProduct['found'] ?? false) && isset($matchedProduct['quote_data']['IdLeadOut'])) {
            $idLeadOut = $matchedProduct['quote_data']['IdLeadOut'];
        }

        // If not found in matched product, try from dual quotation results
        if (! $idLeadOut) {
            // Get the quotation data that was actually used
            $sourceQuotationType = $matchedProduct['source_quotation'] ?? $actualQuotationType;
            $quotationResult = $dualQuotationResult[$sourceQuotationType]['result'] ?? null;

            if ($quotationResult && ($quotationResult['success'] ?? false)) {
                $idLeadOut = $this->extractIdLeadOut($quotationResult['quotation_data'] ?? $quotationResult);
            }
        }

        // If still not found, try from selectedQuotation
        if (! $idLeadOut) {
            $idLeadOut = $this->extractIdLeadOut($selectedQuotation);
        }

        // Set the extracted IdLeadOut to the voucher data
        if ($idLeadOut) {
            $voucherData['LeadId'] = $idLeadOut;
        }

        // Set the EXACT price from the matched product
        if (! empty($exactPrecioEmision) && is_numeric($exactPrecioEmision)) {
            $voucherData['Precio'] = strval($exactPrecioEmision);
        } else {
            $voucherData['Precio'] = '0.00';
        }

        // Use the plan name directly as requested - no matching needed
        if (isset($personData['plan']['name']) && ! empty($personData['plan']['name'])) {
            $voucherData['DatosProducto']['NombreProducto'] = $personData['plan']['name'];
        }

        // Generate proper control numbers like quotations do
        $controlNumbers = $this->client->generateSequentialControlNumbers();
        $voucherData['NroControl'] = $controlNumbers['base'] . '-' . $this->client->getControlNumberSuffixForQuotationType($actualQuotationType);

        // Add delay to ensure unique timestamps
        $delayMs = $personType === 'titular' ? 5000 : 10000;
        usleep($delayMs);

        try {
            // Create the actual voucher using the proper voucher creation method
            $result = $this->client->createVoucher($voucherData, false);

            // Convert result to arrays and add metadata
            $result = $this->convertObjectsToArrays($result);

            // Simplify the voucher result to avoid duplicate quote responses
            $simplifiedVoucherData = [
                'quotation_type' => $result['quotation_type'] ?? $actualQuotationType,
                'control_number' => $result['control_number'] ?? '',
                'organization' => $result['organization'] ?? '',
                'convenio' => $result['convenio'] ?? $exactConvenio,
                'origin_country_code' => $result['origin_country_code'] ?? '',
                'destination_country_code' => $result['destination_country_code'] ?? '',
                'origin_country_name' => $result['origin_country_name'] ?? '',
                'destination_name' => $result['destination_name'] ?? '',
                'voucher_response' => $result['voucher_response'] ?? null,  // Only include actual voucher creation response
            ];

            // Include one clean quote_response for reference (remove duplicates)
            if (isset($result['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'])) {
                $simplifiedVoucherData['quote_response'] = [
                    'UALeadCotizadorResp' => [
                        'DatosLeadCotizadorOut' => $result['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'],
                    ],
                    'quotation_input_data' => $result['quote_response']['quotation_input_data'] ?? null,
                ];
            }

            return [
                'success' => true,
                'voucher_data' => $simplifiedVoucherData,
                'convenio_used' => $exactConvenio,
                'quotation_type_used' => $selectedQuotation['quotation_type'] ?? 'unknown',
                'voucher_request_input' => $voucherData,  // Include the original voucher request data
                'matched_product' => $matchedProduct,      // Include product matching details
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Voucher creation failed: ' . $e->getMessage(),
                'convenio_attempted' => $exactConvenio,
                'quotation_type_attempted' => $selectedQuotation['quotation_type'] ?? 'unknown',
                'voucher_request_input' => $voucherData,  // Include the request data even on error
                'matched_product' => $matchedProduct,      // Include product matching details even on error
            ];
        }
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
                'reason' => 'Missing search plan',
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

            // Clean both strings for comparison - remove extra spaces and normalize
            $cleanedSearchPlan = preg_replace('/\s+/', ' ', $searchPlanLower);
            $cleanedProductName = preg_replace('/\s+/', ' ', $nombreProductoLower);

            // Only exact match - no partial matching
            if ($cleanedProductName === $cleanedSearchPlan) {
                return [
                    'found' => true,
                    'product_name' => $nombreProducto,
                    'match_type' => 'exact_match',
                    'quote_data' => $singleQuote,
                    'quote_index' => $index,
                    'comparison_debug' => [
                        'original_search' => $searchPlan,
                        'cleaned_search' => $cleanedSearchPlan,
                        'original_product' => $nombreProducto,
                        'cleaned_product' => $cleanedProductName,
                    ],
                ];
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
            'quotes_checked' => count($quotesToCheck),
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
     * Handles multiple possible response structures from Universal Assistance API
     */
    protected function extractIdLeadOut($quotationResponse): ?string
    {
        if (empty($quotationResponse)) {
            return null;
        }

        // Convert to array for consistent handling
        if (is_object($quotationResponse)) {
            $quotationResponse = $this->convertObjectsToArrays($quotationResponse);
        }

        // LEVEL 1: Direct IdLeadOut field (simple case)
        if (isset($quotationResponse['IdLeadOut']) && ! empty($quotationResponse['IdLeadOut'])) {
            return (string) $quotationResponse['IdLeadOut'];
        }

        // LEVEL 2: Check in DatosLeadCotizadorOut (direct structure)
        if (isset($quotationResponse['DatosLeadCotizadorOut'])) {
            $quoteData = $quotationResponse['DatosLeadCotizadorOut'];

            // Handle array case - take first element
            if (is_array($quoteData) && ! empty($quoteData) && isset($quoteData[0])) {
                $firstQuote = $quoteData[0];
                $idLeadOut = is_array($firstQuote) ? ($firstQuote['IdLeadOut'] ?? null) : ($firstQuote->IdLeadOut ?? null);
                if ($idLeadOut) {
                    return (string) $idLeadOut;
                }
            }

            // Handle object/associative array case
            if (is_array($quoteData) && isset($quoteData['IdLeadOut'])) {
                return (string) $quoteData['IdLeadOut'];
            }
        }

        // LEVEL 3: Check in UALeadCotizadorResp/DatosLeadCotizadorOut (standard UA response)
        if (isset($quotationResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'])) {
            $quoteData = $quotationResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'];

            // Handle array case - take first element
            if (is_array($quoteData) && ! empty($quoteData) && isset($quoteData[0])) {
                $firstQuote = $quoteData[0];
                $idLeadOut = is_array($firstQuote) ? ($firstQuote['IdLeadOut'] ?? null) : ($firstQuote->IdLeadOut ?? null);
                if ($idLeadOut) {
                    return (string) $idLeadOut;
                }
            }

            // Handle object/associative array case
            if (is_array($quoteData) && isset($quoteData['IdLeadOut'])) {
                return (string) $quoteData['IdLeadOut'];
            }
        }

        // LEVEL 4: Check in quote_response structure (client response wrapper)
        if (isset($quotationResponse['quote_response'])) {
            $quoteResponseData = $quotationResponse['quote_response'];

            // Recursive call with quote_response data
            $quoteResult = $this->extractIdLeadOut($quoteResponseData);
            if ($quoteResult) {
                return $quoteResult;
            }
        }

        // LEVEL 5: Check in response structure (client response wrapper)
        if (isset($quotationResponse['response'])) {
            $responseData = $quotationResponse['response'];

            // Recursive call with response data
            $responseResult = $this->extractIdLeadOut($responseData);
            if ($responseResult) {
                return $responseResult;
            }
        }

        // LEVEL 6: Check in nested quotation_data structure (from performGroupQuotation)
        if (isset($quotationResponse['quotation_data'])) {
            $nestedData = $quotationResponse['quotation_data'];

            // Recursive call with nested data
            $nestedResult = $this->extractIdLeadOut($nestedData);
            if ($nestedResult) {
                return $nestedResult;
            }
        }

        // LEVEL 7: Check in result structure (workflow result wrapper)
        if (isset($quotationResponse['result'])) {
            $resultData = $quotationResponse['result'];

            // Recursive call with result data
            $resultResult = $this->extractIdLeadOut($resultData);
            if ($resultResult) {
                return $resultResult;
            }
        }

        // LEVEL 8: Deep search in any array structure (last resort)
        if (is_array($quotationResponse)) {
            foreach ($quotationResponse as $key => $value) {
                if ($key === 'IdLeadOut' && $value) {
                    return (string) $value;
                }

                // Search in nested arrays/objects
                if ((is_array($value) || is_object($value)) && ! empty($value)) {
                    $deepResult = $this->extractIdLeadOut($value);
                    if ($deepResult) {
                        return $deepResult;
                    }
                }
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

        // Calculate dates based on eSIM plan duration, not voucher validity dates
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = $this->getProductDuration($personData); // This now correctly gets eSIM plan duration

        // Calculate expiration date based on actual eSIM plan duration
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration - 1); // Subtract 1 because if plan is 5 days, it should end 4 days after start (inclusive)

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
            'Contrato' => $convenio, // Use uppercase 'Contrato' to match WSDL specification
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-FOT6XKT', // PROD fallback
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
                'SexoSolicitante' => strtoupper($personData['sex'] ?? $personData['gender'] ?? 'M'), // Ensure uppercase for UA API
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'TituloCortesiaSolicitante' => 'Sr.', // Default courtesy title
                'EdadSolicitante' => Carbon::parse($personData['dob'])->age,
                'CorreoElectronicoSolicitante' => $personData['email'], // Email field for voucher delivery
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

        // Calculate dates based on eSIM plan duration, not voucher validity dates
        $activationDate = Carbon::parse($personData['activationDate']);
        $duration = $this->getProductDuration($personData); // This now correctly gets eSIM plan duration

        // Calculate expiration date based on actual eSIM plan duration
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration - 1); // Subtract 1 because if plan is 5 days, it should end 4 days after start (inclusive)

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
            'Contrato' => $convenio, // Use uppercase 'Contrato' to match WSDL specification
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'Tarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-FOT6XKT', // PROD fallback
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
                'SexoSolicitante' => strtoupper($personData['sex'] ?? $personData['gender'] ?? 'M'), // Ensure uppercase for UA API
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'TituloCortesiaSolicitante' => 'Sr.', // Default courtesy title
                'EdadSolicitante' => Carbon::parse($personData['dob'])->age,
                'CorreoElectronicoSolicitante' => $personData['email'], // Email field for voucher delivery
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

    /**
     * Extract convenio with multiple fallback sources
     */
    protected function extractConvenioWithFallback(array $selectedQuotation, array $personData): string
    {
        // PRIORITY 1: convenio_used from selectedQuotation is ALWAYS the first priority
        if (! empty($selectedQuotation['convenio_used'] ?? null)) {
            return $selectedQuotation['convenio_used'];
        }

        // PRIORITY 2: convenio_used from personData is second priority
        if (! empty($personData['convenio_used'] ?? null)) {
            return $personData['convenio_used'];
        }

        // PRIORITY 3: Use variant-based convenio logic (MAIN LOGIC)
        $planVariant = $this->extractVariantType($personData);

        // Smart default for quotation type based on variant
        $defaultQuotationType = $planVariant === 'basic' ? 'inclusion' : 'inclusion_ii';
        $quotationType = $selectedQuotation['quotation_type'] ?? $defaultQuotationType;

        // Determine convenios based on variant type (same logic as performDualQuotationWorkflow)
        if ($planVariant === 'basic') {
            // Basic → TELEASISTENCIA convenios (type I)
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_I->value);
        } else {
            // Unlimited → ASISTENCIA 10K REC convenios (type II)
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_II->value);
        }

        // Return appropriate convenio based on quotation type (handle all quotation types)
        if (in_array($quotationType, ['cross_selling', 'cross_selling_ii'])) {
            return $crossSellingConvenio;
        } elseif (in_array($quotationType, ['inclusion', 'inclusion_ii'])) {
            return $inclusionConvenio;
        } else {
            // Fallback for unknown quotation types - use variant-based default
            return $planVariant === 'basic' ? $inclusionConvenio : $inclusionConvenio;
        }
    }

    /**
     * Generate a unique plan key for grouping people with same plan
     * People with the same plan key will be grouped into a single voucher
     */
    public function generatePlanGroupKey(array $personData): string
    {
        // Extract plan information - the key factors for grouping
        $planName = $personData['plan']['name'] ?? 'unknown';

        // Get country codes to ensure same origin/destination
        $originCountryCode = $personData['originCountryCode'] ?? 'unknown';
        $destinationCountryCode = $personData['destinationCountryCode'] ?? $personData['destinyCountryCode'] ?? 'unknown';

        // Create a composite key that identifies the same plan/destination combination
        // Format: plan_name|origin_country|destination_country
        return implode('|', [
            $planName,
            strtoupper($originCountryCode),
            strtoupper($destinationCountryCode),
        ]);
    }

    /**
     * Process grouped insurance workflow for people with the same plan
     * Creates a single voucher for the entire group
     */
    public function processGroupedInsuranceWorkflow(array $groupedPersonsData, string $planGroupKey): array
    {
        if (empty($groupedPersonsData)) {
            throw new ValidationException('No grouped persons data found');
        }

        // Use the first person's data to determine common parameters
        $firstPerson = $groupedPersonsData[0];
        $originCountryCode = $firstPerson['originCountryCode'] ?? 'AR';
        $destinationCountryCode = $firstPerson['destinationCountryCode'] ?? $firstPerson['destinyCountryCode'] ?? 'DO';

        // Convert any objects to arrays to prevent stdClass errors
        $groupedPersonsData = $this->convertObjectsToArrays($groupedPersonsData);

        // Perform dual quotation using ALL persons in the group (not just first person)
        $dualQuotationResults = $this->performGroupDualQuotationWorkflow($groupedPersonsData, $originCountryCode, $destinationCountryCode);

        // Select best quotation (same logic as individual processing)
        $selectedQuotation = $this->selectBestQuotationForGroupVoucher($dualQuotationResults, $firstPerson);

        // Create a single voucher for the entire group with all people included
        $groupVoucherResult = $this->createGroupVoucher($groupedPersonsData, $selectedQuotation, $originCountryCode, $destinationCountryCode);

        return [
            'plan_group_key' => $planGroupKey,
            'group_size' => count($groupedPersonsData),
            'persons_in_group' => $groupedPersonsData,
            'dual_quotation_results' => $this->simplifyDualQuotationResults($dualQuotationResults),
            'selected_quotation' => $selectedQuotation,
            'group_voucher_result' => $groupVoucherResult,
            'workflow_type' => 'grouped_voucher_by_plan',
            'input_plan_requested' => $firstPerson['plan']['name'] ?? 'Unknown plan',
            'selected_product_match' => $groupVoucherResult['matched_product'] ?? null,
            'quotation_summary' => [
                'origin_country' => $originCountryCode,
                'destination_country' => $destinationCountryCode,
                'variant_type' => $this->extractVariantType($firstPerson),
                'duration_days' => $this->getProductDuration($firstPerson),
                'group_size' => count($groupedPersonsData),
                'quotation_timestamp' => now()->toISOString(),
            ],
        ];
    }

    /**
     * Select best quotation for group voucher (same logic as individual)
     */
    protected function selectBestQuotationForGroupVoucher(array $dualQuotationResults, array $referencePerson): array
    {
        // Use same logic as individual voucher selection
        $targetPlan = $referencePerson['plan']['name'] ?? '';

        // Check inclusion first (preferred for matching plan names)
        if (isset($dualQuotationResults['inclusion']['result']['success']) &&
            $dualQuotationResults['inclusion']['result']['success']) {
            return $dualQuotationResults['inclusion'];
        }

        // Fallback to cross_selling if inclusion failed
        if (isset($dualQuotationResults['cross_selling']['result']['success']) &&
            $dualQuotationResults['cross_selling']['result']['success']) {
            return $dualQuotationResults['cross_selling'];
        }

        // If both failed, return inclusion for error handling
        return $dualQuotationResults['inclusion'];
    }

    /**
     * Create a group voucher for multiple people with the same plan
     */
    protected function createGroupVoucher(array $groupedPersonsData, array $selectedQuotation, string $originCountryCode, string $destinationCountryCode): array
    {
        // Use the selected quotation's convenio and quotation type
        $convenio = $selectedQuotation['convenio'];
        $quotationType = $selectedQuotation['quotation_type'] ?? $selectedQuotation['type'];

        // Extract the quoted price from the quotation data for the voucher
        $quotationData = $selectedQuotation['result']['quotation_data'] ?? $selectedQuotation['quotation_data'] ?? [];
        $quotedPrice = $this->extractQuotedPriceFromGroupQuotation($quotationData);

        if (empty($quotedPrice) || $quotedPrice === '0.00') {
            // Try alternative price extraction methods if primary method failed
            $alternativePaths = [
                $selectedQuotation['result'] ?? null,
                $selectedQuotation['quotation_request_input'] ?? null,
                $selectedQuotation ?? null,
            ];

            foreach ($alternativePaths as $path) {
                if ($path && is_array($path)) {
                    $altPrice = $this->extractQuotedPriceFromGroupQuotation($path);
                    if ($altPrice !== '0.00') {
                        $quotedPrice = $altPrice;

                        break;
                    }
                }
            }
        }

        // CRITICAL: Extract IdLeadOut from the selected quotation to pass to voucher
        // For group quotations, try multiple possible paths
        $idLeadOut = null;

        // Path 1: Try from result.quotation_data first
        if (isset($selectedQuotation['result']['quotation_data'])) {
            $idLeadOut = $this->extractIdLeadOut($selectedQuotation['result']['quotation_data']);
        }

        // Path 2: Try from result.quotation_request_input (fallback)
        if (! $idLeadOut && isset($selectedQuotation['result']['quotation_request_input'])) {
            $idLeadOut = $this->extractIdLeadOut($selectedQuotation['result']['quotation_request_input']);
        }

        // Path 3: Try from the full selectedQuotation structure
        if (! $idLeadOut) {
            $idLeadOut = $this->extractIdLeadOut($selectedQuotation);
        }

        // Extract IdLeadOut validation completed

        // Create voucher data for the group using ONLY the primary person (no DatosBeneficiarios)
        $primaryPerson = $groupedPersonsData[0];

        // Build voucher data with ONLY primary person and correct price
        $voucherData = $this->buildSinglePersonVoucherWithGroupPrice($primaryPerson, $originCountryCode, $destinationCountryCode, $convenio, $quotedPrice, $quotationType);

        // CRITICAL: Set the LeadId from the quotation
        if ($idLeadOut) {
            $voucherData['LeadId'] = $idLeadOut;
        }

        // Generate proper control numbers like quotations do
        $controlNumbers = $this->client->generateSequentialControlNumbers();
        $voucherData['NroControl'] = $controlNumbers['base'] . '-' . $this->client->getControlNumberSuffixForQuotationType($quotationType);

        // Create the voucher using the proper voucher creation method
        try {
            $result = $this->client->createVoucher($voucherData, false);

            return [
                'success' => true,
                'voucher_data' => $this->convertObjectsToArrays($result),
                'voucher_request_input' => $voucherData,
                'convenio_used' => $convenio,
                'quotation_type_used' => $quotationType,
                'group_size' => count($groupedPersonsData),
                'people_names' => $this->extractPeopleNames($groupedPersonsData),
                'id_lead_out_used' => $idLeadOut, // For debugging
                'quote_response' => $quotationData, // Include the original quotation response
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'voucher_request_input' => $voucherData,
                'convenio_used' => $convenio,
                'quotation_type_used' => $quotationType,
                'group_size' => count($groupedPersonsData),
                'people_names' => $this->extractPeopleNames($groupedPersonsData),
                'id_lead_out_used' => $idLeadOut, // For debugging
                'quote_response' => $quotationData, // Include the original quotation response
            ];
        }
    }

    /**
     * Extract quoted price from group quotation data - Enhanced to match individual voucher logic
     */
    protected function extractQuotedPriceFromGroupQuotation(array $quotationData): string
    {
        // Try multiple paths to find the price, similar to individual voucher logic
        $searchPaths = [
            // Path 1: Direct UALeadCotizadorResp structure
            $quotationData['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
            // Path 2: quote_response structure
            $quotationData['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
            // Path 3: response structure
            $quotationData['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ?? null,
            // Path 4: Direct DatosLeadCotizadorOut
            $quotationData['DatosLeadCotizadorOut'] ?? null,
        ];

        foreach ($searchPaths as $cotizadorData) {
            if ($cotizadorData !== null) {
                // Handle both single object and array of objects
                if (is_array($cotizadorData) && isset($cotizadorData[0])) {
                    $cotizadorData = $cotizadorData[0];
                }

                // Try multiple price fields in order of preference
                $price = $cotizadorData['PrecioEmision'] ??
                        $cotizadorData['PrecioNeto'] ??
                        $cotizadorData['PrecioBruto'] ??
                        null;

                if ($price !== null && is_numeric($price) && floatval($price) > 0) {
                    return strval($price);
                }
            }
        }

        // Additional fallback: Try to extract from any nested structures
        if (is_array($quotationData)) {
            foreach ($quotationData as $key => $value) {
                if (is_array($value) && strpos($key, 'Lead') !== false) {
                    $nestedPrice = $this->extractQuotedPriceFromGroupQuotation($value);
                    if ($nestedPrice !== '0.00') {
                        return $nestedPrice;
                    }
                }
            }
        }

        return '0.00';
    }

    /**
     * Build voucher data for single person but with group-calculated price
     * This creates a voucher with ONLY the primary person but the price calculated for the entire group
     */
    protected function buildSinglePersonVoucherWithGroupPrice(array $primaryPerson, string $originCountryCode, string $destinationCountryCode, string $convenio, string $groupPrice, string $quotationType): array
    {
        // Build voucher data for just the primary person
        if ($quotationType === 'cross_selling') {
            $voucherData = $this->buildCrossSellingVoucherDataWithConvenio($primaryPerson, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        } else {
            $voucherData = $this->buildVoucherDataWithConvenio($primaryPerson, 'titular', $originCountryCode, $destinationCountryCode, $convenio);
        }

        // CRITICAL: Override the price with the group-calculated price
        // Ensure we have a valid price before setting it
        if (! empty($groupPrice) && is_numeric($groupPrice) && floatval($groupPrice) > 0) {
            $voucherData['Precio'] = strval($groupPrice);
        } else {
            // If group price is invalid, try to extract from individual plan
            $fallbackPrice = $primaryPerson['plan']['price'] ?? '0.00';
            if (is_numeric($fallbackPrice) && floatval($fallbackPrice) > 0) {
                $voucherData['Precio'] = strval($fallbackPrice);
            } else {
                $voucherData['Precio'] = '0.00';
            }
        }

        // CRITICAL: Remove DatosBeneficiarios to ensure only primary person in voucher
        unset($voucherData['DatosBeneficiarios']);

        return $voucherData;
    }

    /**
     * Extract people names from group for logging
     */
    protected function extractPeopleNames(array $groupedPersonsData): array
    {
        $names = [];
        foreach ($groupedPersonsData as $person) {
            $firstName = $person['firstName'] ?? $person['firstname'] ?? 'Unknown';
            $lastName = $person['lastName'] ?? $person['lastname'] ?? 'Person';
            $names[] = "{$firstName} {$lastName}";
        }

        return $names;
    }

    /**
     * Build group voucher data with convenio for inclusion type
     */
    protected function buildGroupVoucherDataWithConvenio(array $primaryPerson, array $additionalBeneficiaries, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // Start with primary person's voucher data
        $primaryVoucherData = $this->buildVoucherDataWithConvenio($primaryPerson, 'titular', $originCountryCode, $destinationCountryCode, $convenio);

        // Add additional beneficiaries to the voucher
        if (! empty($additionalBeneficiaries)) {
            $beneficiaries = [];
            foreach ($additionalBeneficiaries as $index => $beneficiary) {
                $beneficiaryData = $this->buildVoucherDataWithConvenio($beneficiary, 'dependent', $originCountryCode, $destinationCountryCode, $convenio);
                $beneficiaries[] = $beneficiaryData['DatosSolicitante']; // Extract just the person data
            }

            // Add beneficiaries to the primary voucher data
            $primaryVoucherData['DatosBeneficiarios'] = $beneficiaries;
        }

        return $primaryVoucherData;
    }

    /**
     * Build group voucher data with convenio for cross-selling type
     */
    protected function buildCrossSellingGroupVoucherDataWithConvenio(array $primaryPerson, array $additionalBeneficiaries, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // Start with primary person's cross-selling voucher data
        $primaryVoucherData = $this->buildCrossSellingVoucherDataWithConvenio($primaryPerson, 'titular', $originCountryCode, $destinationCountryCode, $convenio);

        // Add additional beneficiaries to the cross-selling voucher
        if (! empty($additionalBeneficiaries)) {
            $beneficiaries = [];
            foreach ($additionalBeneficiaries as $index => $beneficiary) {
                $beneficiaryData = $this->buildCrossSellingVoucherDataWithConvenio($beneficiary, 'dependent', $originCountryCode, $destinationCountryCode, $convenio);
                $beneficiaries[] = $beneficiaryData['DatosSolicitante']; // Extract just the person data
            }

            // Add beneficiaries to the primary voucher data
            $primaryVoucherData['DatosBeneficiarios'] = $beneficiaries;
        }

        return $primaryVoucherData;
    }

    /**
     * Perform dual quotation workflow for a GROUP of people with same plan
     * Important: Includes ALL people in the quote so Universal Assistance can properly price based on ages and quantity
     */
    protected function performGroupDualQuotationWorkflow(array $groupedPersonsData, string $originCountryCode, string $destinationCountryCode): array
    {
        // CRITICAL FIX: Convert flat array to titular/dependents structure if needed
        if (! isset($groupedPersonsData['titular']) && ! isset($groupedPersonsData['dependents'])) {
            // This is a flat array - convert to titular/dependents structure
            if (count($groupedPersonsData) < 2) {
                throw new ValidationException('Group quotation requires at least 2 people, but only ' . count($groupedPersonsData) . ' provided');
            }

            // Convert flat array to nested structure
            $restructuredData = [
                'titular' => $groupedPersonsData[0], // First person is titular
                'dependents' => array_slice($groupedPersonsData, 1) // Rest are dependents
            ];

            $groupedPersonsData = $restructuredData;
        }

        $firstPerson = null;

        if (isset($groupedPersonsData['titular'])) {
            // Nested structure - use titular as the primary person
            $firstPerson = $groupedPersonsData['titular'];
        } elseif (isset($groupedPersonsData[0])) {
            // Flat array structure - use first person
            $firstPerson = $groupedPersonsData[0];
        } else {
            throw new ValidationException('Unable to extract first person from group data for variant detection');
        }

        $planVariant = $this->extractVariantType($firstPerson);
        $targetPlan = $firstPerson['plan']['name'] ?? '';

        // Determine convenios and quotation types based on variant type
        if ($planVariant === 'basic') {
            // Basic → TELEASISTENCIA convenios (type I)
            $inclusionType = 'inclusion';
            $crossSellingType = 'cross_selling';
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_I->value);
        } else {
            // Unlimited → ASISTENCIA 10K REC convenios (type II)
            $inclusionType = 'inclusion_ii';
            $crossSellingType = 'cross_selling_ii';
            $inclusionConvenio = $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value);
            $crossSellingConvenio = $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_II->value);
        }

        // Calculate actual group size for reporting
        $actualGroupSize = 0;
        if (isset($groupedPersonsData['titular'])) {
            $actualGroupSize = 1; // titular
            if (isset($groupedPersonsData['dependents']) && is_array($groupedPersonsData['dependents'])) {
                $actualGroupSize += count($groupedPersonsData['dependents']);
            }
        } else {
            $actualGroupSize = count($groupedPersonsData);
        }

        // Perform inclusion quotation with ALL people in group and correct type
        $inclusionResult = $this->performGroupQuotation($groupedPersonsData, $originCountryCode, $destinationCountryCode, $inclusionType, $inclusionConvenio);

        // Add delay between quotations
        usleep(5000);

        // Perform cross selling quotation with ALL people in group and correct type
        $crossSellingResult = $this->performGroupQuotation($groupedPersonsData, $originCountryCode, $destinationCountryCode, $crossSellingType, $crossSellingConvenio);

        return [
            'inclusion' => [
                'type' => $inclusionType,
                'convenio' => $inclusionConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'group_size' => $actualGroupSize,
                'result' => $inclusionResult,
            ],
            'cross_selling' => [
                'type' => $crossSellingType,
                'convenio' => $crossSellingConvenio,
                'target_plan' => $targetPlan,
                'variant' => $planVariant,
                'group_size' => $actualGroupSize,
                'result' => $crossSellingResult,
            ],
            'timestamp' => now()->toISOString(),
            'selection_logic' => [
                'variant' => $planVariant,
                'target_plan' => $targetPlan,
                'group_size' => $actualGroupSize, // Use consistent actual group size
                'origin_country_code' => $originCountryCode,
                'inclusion_type' => $inclusionType,
                'cross_selling_type' => $crossSellingType,
                'inclusion_convenio' => $inclusionConvenio,
                'cross_selling_convenio' => $crossSellingConvenio,
            ],
        ];
    }

    /**
     * Perform a group quotation with ALL people included for proper pricing
     * Universal Assistance needs all ages and person count to calculate correct group pricing
     */
    protected function performGroupQuotation(array $groupedPersonsData, string $originCountryCode, string $destinationCountryCode, string $quotationType, string $convenio): array
    {
        // Calculate the actual group size before building quotation data
        $actualGroupSize = 0;
        if (isset($groupedPersonsData['titular'])) {
            $actualGroupSize = 1; // titular
            if (isset($groupedPersonsData['dependents']) && is_array($groupedPersonsData['dependents'])) {
                $actualGroupSize += count($groupedPersonsData['dependents']);
            }
        } else {
            $actualGroupSize = count($groupedPersonsData);
        }

        // Build group QUOTATION data (different from voucher data - includes all ages in Edad1, Edad2, etc.)
        $quotationData = $this->buildGroupQuotationData($groupedPersonsData, $originCountryCode, $destinationCountryCode, $convenio);

        // Perform the quotation using the client with ALL group members properly structured
        try {
            $result = $this->client->createSingleQuotationWithCountries(
                $quotationData,
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
                'quotation_type' => $quotationType,
                'group_size' => $actualGroupSize, // Use calculated actual group size
                'quotation_request_input' => $quotationData,  // Include the original quotation request data with ALL people
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'convenio' => $convenio,
                'quotation_type' => $quotationType,
                'group_size' => $actualGroupSize, // Use calculated actual group size
                'quotation_request_debug' => $quotationData ?? null, // Include quotation data for debugging on error
            ];
        }
    }

    /**
     * Build quotation data for a group (different from voucher data)
     * For quotation, we need to include all ages in Edad1, Edad2, Edad3... and CantidadPasajeros
     * This is used for getting quotes, not for creating vouchers
     */
    protected function buildGroupQuotationData(array $groupedPersonsData, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // VALIDATION: Ensure we received the expected group data
        if (empty($groupedPersonsData)) {
            throw new ValidationException('buildGroupQuotationData called with empty group data');
        }

        // CRITICAL FIX: Handle both flat array of people and nested structure with titular/dependents
        $flatPersonsArray = [];

        // Check if this is a nested structure (titular/dependents) or flat array
        if (isset($groupedPersonsData['titular']) || isset($groupedPersonsData['dependents'])) {
            // Nested structure - extract titular and dependents into flat array
            if (isset($groupedPersonsData['titular']) && is_array($groupedPersonsData['titular'])) {
                $flatPersonsArray[] = $groupedPersonsData['titular'];
            }

            if (isset($groupedPersonsData['dependents']) && is_array($groupedPersonsData['dependents'])) {
                foreach ($groupedPersonsData['dependents'] as $dependent) {
                    if (is_array($dependent)) {
                        $flatPersonsArray[] = $dependent;
                    }
                }
            }
        } else {
            // Check if it's already a flat array of person objects
            $isFlat = true;
            foreach ($groupedPersonsData as $key => $item) {
                if (! is_numeric($key) && ! is_array($item)) {
                    $isFlat = false;

                    break;
                }
                // Check if item has person-like structure
                if (! isset($item['firstname']) && ! isset($item['firstName']) &&
                    ! isset($item['dob']) && ! isset($item['birthDate'])) {
                    $isFlat = false;

                    break;
                }
            }

            if ($isFlat) {
                $flatPersonsArray = $groupedPersonsData;
            } else {
                throw new ValidationException('buildGroupQuotationData received unrecognized data structure. Expected either flat array of people or titular/dependents structure.');
            }
        }

        $groupSize = count($flatPersonsArray);

        // DEBUGGING: Log what we received to identify the data structure issue
        $debugInfo = [];
        foreach ($flatPersonsArray as $index => $person) {
            $firstName = $person['firstname'] ?? $person['firstName'] ?? 'Unknown';
            $lastName = $person['lastname'] ?? $person['lastName'] ?? 'Person';
            $birthDate = $person['dob'] ?? $person['birthDate'] ?? null;
            $debugInfo[] = "Person {$index}: {$firstName} {$lastName} (DOB: {$birthDate})";
        }

        // If we only have 1 person but expecting a family group, this indicates the family grouping failed
        if ($groupSize === 1) {
            // Check if this person has family information that wasn't processed correctly
            $person = $flatPersonsArray[0];
            $errorMsg = 'Group quotation called with only 1 person - family grouping may have failed. ';
            $errorMsg .= 'Person: ' . implode(', ', $debugInfo);

            throw new ValidationException($errorMsg);
        }

        // Log detailed group information for debugging
        $peopleNames = [];
        foreach ($flatPersonsArray as $person) {
            $firstName = $person['firstname'] ?? $person['firstName'] ?? 'Unknown';
            $lastName = $person['lastname'] ?? $person['lastName'] ?? 'Person';
            $peopleNames[] = "{$firstName} {$lastName}";
        }

        // Get primary person for basic data
        $primaryPerson = $flatPersonsArray[0];

        // Calculate ages for all people in the group
        // CRITICAL: Ensure ALL people are included with proper ages
        $ages = [];
        $ageDetails = [];

        foreach ($flatPersonsArray as $index => $person) {
            $firstName = $person['firstname'] ?? $person['firstName'] ?? 'Unknown';
            $lastName = $person['lastname'] ?? $person['lastName'] ?? 'Person';
            $birthDate = $person['dob'] ?? $person['birthDate'] ?? null;

            if ($birthDate) {
                $age = $this->calculateAge($birthDate);
                $ages[] = $age;
                $ageDetails[] = "{$firstName} {$lastName}: {$age} años (DOB: {$birthDate})";
            } else {
                // If birth date is missing, use a default age to ensure person is still included in quotation
                // This prevents quotation from having fewer people than expected
                $defaultAge = 30; // Default adult age for missing birth dates
                $ages[] = $defaultAge;
                $ageDetails[] = "{$firstName} {$lastName}: {$defaultAge} años (DOB missing - using default)";
            }
        }

        // Get travel dates
        $activationDate = $primaryPerson['activationDate'] ?? null;
        $expirationDate = $primaryPerson['expirationDate'] ?? null;

        $fechaInicio = $activationDate ? \DateTime::createFromFormat('Y-m-d', $activationDate)->format('m/d/Y') : '';
        $fechaFin = $expirationDate ? \DateTime::createFromFormat('Y-m-d', $expirationDate)->format('m/d/Y') : '';

        // Get destination info
        $originCountryName = $this->getCountryName($originCountryCode);
        $destinationName = $this->getDestinationName($destinationCountryCode);

        // Build quotation data structure (UALeadCotizadorReq format)
        $quotationData = [
            'IdLead' => '',
            'OrganizacionEmisora' => $this->client->getOrganizationForQuotationType('quotation'),
            'CantCotizaciones' => 1,
            'Convenio' => $convenio,
            'Folleto' => '',
            'PaisOrigen' => $originCountryName,
            'Destino' => $destinationName,
            'TipoViaje' => 'Un viaje',
            'FechaInicio' => $fechaInicio,
            'FechaFin' => $fechaFin,
            'CantidadPasajeros' => $groupSize, // CRITICAL: Total number of people in group
            'PackFamiliar' => '',
            // Add all ages to individual Edad fields - Universal Assistance expects individual age fields
            'Edad1' => $ages[0] ?? '',
            'Edad2' => $ages[1] ?? '',
            'Edad3' => $ages[2] ?? '',
            'Edad4' => $ages[3] ?? '',
            'Edad5' => $ages[4] ?? '',
            'Edad6' => $ages[5] ?? '',
            'Edad7' => $ages[6] ?? '',
            'Edad8' => $ages[7] ?? '',
            'Edad9' => $ages[8] ?? '',
            'Edad10' => $ages[9] ?? '',
            'Categoria' => '',
            'Precompras' => '',
        ];

        // VALIDATION: Ensure all ages were properly assigned
        $agesAssigned = 0;
        for ($i = 1; $i <= 10; $i++) {
            if (! empty($quotationData["Edad{$i}"])) {
                $agesAssigned++;
            }
        }

        // Validate that the number of ages assigned matches the group size
        if ($agesAssigned !== $groupSize) {
            throw new ValidationException("Mismatch in group quotation data: Expected {$groupSize} people but only assigned {$agesAssigned} ages. Ages: " . implode(', ', $ages));
        }

        return $quotationData;
    }

    /**
     * Calculate age from birth date
     */
    protected function calculateAge(string $birthDate): int
    {
        try {
            $birth = new \DateTime($birthDate);
            $today = new \DateTime();
            $age = $today->diff($birth)->y;

            return $age;
        } catch (\Exception $e) {
            return 0; // Default age if parsing fails
        }
    }

    /**
     * Simplify dual quotation results to return only essential data for matching
     * Returns clean structure with just 2 quote responses per person (inclusion + cross_selling)
     */
    protected function simplifyDualQuotationResults(array $dualQuotationResult): array
    {
        $simplified = [
            'inclusion' => [
                'type' => 'inclusion',
                'success' => false,
                'error' => 'No inclusion quotation performed'
            ],
            'cross_selling' => [
                'type' => 'cross_selling',
                'success' => false,
                'error' => 'No cross_selling quotation performed'
            ]
        ];

        // Process inclusion quotation
        if (isset($dualQuotationResult['inclusion']['result']['success']) &&
            $dualQuotationResult['inclusion']['result']['success']) {
            $inclusionData = $dualQuotationResult['inclusion']['result']['quotation_data'] ?? [];
            $inclusionQuote = $inclusionData['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                             $inclusionData['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                             null;

            if ($inclusionQuote) {
                $simplified['inclusion'] = [
                    'type' => 'inclusion',
                    'success' => true,
                    'convenio' => $dualQuotationResult['inclusion']['convenio'] ?? '',
                    'target_plan' => $dualQuotationResult['inclusion']['target_plan'] ?? '',
                    'variant' => $dualQuotationResult['inclusion']['variant'] ?? '',
                    'quote_response' => $inclusionQuote
                ];
            }
        }

        // Process cross_selling quotation
        if (isset($dualQuotationResult['cross_selling']['result']['success']) &&
            $dualQuotationResult['cross_selling']['result']['success']) {
            $crossSellingData = $dualQuotationResult['cross_selling']['result']['quotation_data'] ?? [];
            $crossSellingQuote = $crossSellingData['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                $crossSellingData['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                                null;

            if ($crossSellingQuote) {
                $simplified['cross_selling'] = [
                    'type' => 'cross_selling',
                    'success' => true,
                    'convenio' => $dualQuotationResult['cross_selling']['convenio'] ?? '',
                    'target_plan' => $dualQuotationResult['cross_selling']['target_plan'] ?? '',
                    'variant' => $dualQuotationResult['cross_selling']['variant'] ?? '',
                    'quote_response' => $crossSellingQuote
                ];
            }
        }

        // Add simple summary
        $simplified['summary'] = [
            'inclusion_success' => $simplified['inclusion']['success'] ?? false,
            'cross_selling_success' => $simplified['cross_selling']['success'] ?? false,
            'total_successful' => (int)($simplified['inclusion']['success'] ?? false) + (int)($simplified['cross_selling']['success'] ?? false)
        ];

        return $simplified;
    }

    /**
     * Convert individual voucher data to quotation data with calculated ages
     * This ensures individual quotations have the same structure as group quotations
     */
    protected function buildIndividualQuotationData(array $personData, string $originCountryCode, string $destinationCountryCode, string $convenio): array
    {
        // Get destination name using the proper mapping
        $destination = $this->getDestinationName($destinationCountryCode);
        if (! $this->isValidDestination($destination)) {
            $destination = 'Centro america/Caribe'; // Safe fallback
        }

        // Calculate dates based on eSIM plan duration
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = $this->getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration - 1);

        // Calculate age from birthdate
        $age = $this->calculateAge($personData['dob']);

        return [
            'IdLead' => '',
            'OrganizacionEmisora' => $this->app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-FOT6XKT',
            'CantCotizaciones' => 1,
            'Convenio' => $convenio,
            'Folleto' => '',
            'PaisOrigen' => $this->getCountryName($originCountryCode),
            'Destino' => $destination,
            'TipoViaje' => 'Un viaje',
            'FechaInicio' => $activationDate->format('m/d/Y'),
            'FechaFin' => $expirationDate->format('m/d/Y'),
            'CantidadPasajeros' => 1,
            'PackFamiliar' => '',
            // Set the age for the single person in Edad1
            'Edad1' => $age,
            'Edad2' => '',
            'Edad3' => '',
            'Edad4' => '',
            'Edad5' => '',
            'Edad6' => '',
            'Edad7' => '',
            'Edad8' => '',
            'Edad9' => '',
            'Edad10' => '',
            'Categoria' => '',
            'Precompras' => '',
        ];
    }
}
