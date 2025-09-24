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
     * Process insurance data from cart workflow
     */
    public function processInsuranceWorkflow(array $cartData): array
    {
        $results = [];

        // Extract insurance data from cart items
        $insuranceData = $this->extractInsuranceData($cartData);

        if (empty($insuranceData)) {
            throw new ValidationException('No insurance data found in cart');
        }

        foreach ($insuranceData as $insuranceItem) {
            // Process titular (main applicant)
            if (isset($insuranceItem['titular'])) {
                $results['titular'] = $this->processTitular($insuranceItem['titular']);
            }

            // Process dependents
            if (isset($insuranceItem['dependents']) && ! empty($insuranceItem['dependents'])) {
                $results['dependents'] = [];
                foreach ($insuranceItem['dependents'] as $dependent) {
                    $results['dependents'][] = $this->processDependent($dependent);
                }
            }
        }

        return $results;
    }

    /**
     * Extract insurance data from cart items (updated to match workflow input structure)
     */
    protected function extractInsuranceData(array $cartData): array
    {
        $insuranceData = [];

        if (! isset($cartData['items'])) {
            return $insuranceData;
        }

        // Process cart items created by ProcessInsuranceCartActivity
        $titularData = null;
        $dependents = [];

        foreach ($cartData['items'] as $item) {
            if (isset($item['type']) && isset($item['data'])) {
                if ($item['type'] === 'titular') {
                    $titularData = $item['data'];
                } elseif ($item['type'] === 'dependent') {
                    $dependents[] = $item['data'];
                }
            }
        }

        // Structure data as expected by processInsuranceWorkflow
        if ($titularData) {
            $insuranceItem = ['titular' => $titularData];
            if (!empty($dependents)) {
                $insuranceItem['dependents'] = $dependents;
            }
            $insuranceData[] = $insuranceItem;
        }

        return $insuranceData;
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

        return $this->client->createSingleQuotation($voucherData, $planType, $this->order, false);
    }

    /**
     * Process dependent insurance
     * Dependents don't need individual vouchers - they are covered under the titular's voucher
     * Just validate and store their data in metadata
     */
    protected function processDependent(array $dependentData): array
    {
        // Validate dependent data structure
        if (! $this->validatePersonData($dependentData, 'dependent')) {
            throw new ValidationException('Invalid dependent data structure');
        }

        // Store dependent information in eSim message metadata (no voucher creation needed)
        $this->storeDependentInESimMessageMetadata($dependentData);

        // Return success info without creating any voucher/quotation
        return [
            'success' => true,
            'message' => 'Dependent information stored in metadata',
            'dependent_name' => $dependentData['firstname'] . ' ' . $dependentData['lastname'],
            'stored_in_metadata' => true
        ];
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

        // Log what we found

        // If duration is provided, use it directly without validation
        if ($duration !== null && $duration !== '') {
            $durationInt = (int) $duration;


            if ($durationInt > 0) {
                return $durationInt;
            }
        } else {
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
            }
        }

        // Default to 7 days if no valid duration found
        return 7;
    }

    /**
     * Store dependent information in eSim message metadata (same level as AeroAmbulancia)
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
}
