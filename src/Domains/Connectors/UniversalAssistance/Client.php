<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\Storage;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use SoapClient;
use SoapFault;

class Client
{
    protected ?SoapClient $quoteClient = null;
    protected ?SoapClient $voucherClient = null;
    protected ?SoapClient $queryClient = null;
    protected ?SoapClient $sendReportClient = null;
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected string $organization;
    public bool $isQaEnvironment = false;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        // Get configuration from app settings with QA fallbacks
        $this->baseUrl = (string)($app->get(ConfigurationEnum::BASE_URL->value));
        $this->username = (string)($app->get(ConfigurationEnum::USERNAME->value));
        $this->password = (string)($app->get(ConfigurationEnum::PASSWORD->value));
        $this->organization = (string)($app->get(ConfigurationEnum::ORGANIZATION->value));
    }

    /**
     * Download WSDL from S3 to temporary file for SoapClient usage
     * Auto-cleans old files and only downloads if needed
     */
    protected function downloadWsdlToTemp(string $s3Url, string $filename): string
    {
        $tempDir = 'temp/wsdl/';
        $tempPath = $tempDir . 'ua_' . $filename;
        $now = time();

        if (Storage::disk('local')->exists($tempDir)) {
            $files = Storage::disk('local')->files($tempDir);
            foreach ($files as $file) {
                if (str_starts_with(basename($file), 'ua_') && str_ends_with($file, '.wsdl')) {
                    $lastModified = Storage::disk('local')->lastModified($file);
                    if (($now - $lastModified) > 7200) {
                        Storage::disk('local')->delete($file);
                    }
                }
            }
        }

        if (Storage::disk('local')->exists($tempPath)) {
            $lastModified = Storage::disk('local')->lastModified($tempPath);
            if (($now - $lastModified) < 3600) {
                return Storage::disk('local')->path($tempPath);
            }
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'PHP-UniversalAssistance-Client',
                'method' => 'GET',
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        $wsdlContent = file_get_contents($s3Url, false, $context);

        if ($wsdlContent === false) {
            throw new ValidationException("Failed to download WSDL from S3: {$s3Url}");
        }

        // Save to Laravel storage temp directory
        if (! Storage::disk('local')->put($tempPath, $wsdlContent)) {
            throw new ValidationException("Failed to save WSDL to storage: {$tempPath}");
        }

        return Storage::disk('local')->path($tempPath);
    }

    /**
     * Generate sequential control numbers for dual quotation system
     * Returns base control number for Inclusión (-I) and Cross Selling (-C) quotations
     */
    public function generateSequentialControlNumbers(?\Kanvas\Souk\Orders\Models\Order $order = null): array
    {
        // Generate sequential number based on order ID or timestamp for consistency
        $sequentialNumber = $order ? $order->id : (int)(microtime(true) * 1000) % 10000;

        // Generate base control number with date and sequential counter (no random)
        $baseControlNumber = 'UA-' . date('Ymd') . '-' . str_pad((string)$sequentialNumber, 4, '0', STR_PAD_LEFT);

        $controlNumbers = [
            'inclusion' => $baseControlNumber . '-' . $this->getControlNumberSuffixForQuotationType('inclusion'),         // Inclusión (base quotation)
            'cross_selling' => $baseControlNumber . '-' . $this->getControlNumberSuffixForQuotationType('cross_selling'), // Cross Selling (additional offer)
            'base' => $baseControlNumber,
            'sequence' => $sequentialNumber
        ];

        // Store in order metadata if order is provided
        if ($order) {
            $this->storeControlNumbersInOrder($order, $controlNumbers);
        }

        return $controlNumbers;
    }

    /**
     * Store control numbers in order metadata
     */
    protected function storeControlNumbersInOrder(\Kanvas\Souk\Orders\Models\Order $order, array $controlNumbers): void
    {
        $metadata = $order->metadata ?? [];

        if (! isset($metadata['universalAssistanceData'])) {
            $metadata['universalAssistanceData'] = [];
        }

        $metadata['universalAssistanceData']['control_numbers'] = [
            'base' => $controlNumbers['base'],
            'inclusion' => $controlNumbers['inclusion'],
            'cross_selling' => $controlNumbers['cross_selling'],
            'sequence' => $controlNumbers['sequence'],
            'generated_at' => now()->toISOString(),
        ];

        $order->metadata = $metadata;
        $order->saveOrFail();
    }

    /**
     * Get organization code for specific quotation type
     */
    public function getOrganizationForQuotationType(string $type): string
    {
        return $this->app->get(ConfigurationEnum::ORGANIZATION->value);
    }

    /**
     * Get convenio (contract) code for specific quotation type
     * This is what actually changes between quotation types
     */
    public function getConvenioForQuotationType(string $type): string
    {
        // Obtener los convenios desde la configuración de la aplicación
        switch ($type) {
            case 'inclusion':
                return $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value);
            case 'inclusion_ii':
                return $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value);
            case 'cross_selling':
                return $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_I->value);
            case 'cross_selling_ii':
                return $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_II->value);
            case 'stand_alone':
                return $this->app->get(ConfigurationEnum::CONVENIO_STAND_ALONE->value);
            default:
                return $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value);
        }
    }

    /**
     * Get convenio (contract) code based on origin/destination countries and quotation type
     * Based on the Universal Assistance coverage table logic
     */
    public function getConvenioForCountries(string $originCountryCode, string $destinationCountryCode, string $quotationType = 'inclusion'): string
    {
        // Normalize country codes
        $originCode = strtoupper($originCountryCode);
        $destinationCode = strtoupper($destinationCountryCode);

        // Determine travel type based on Dominican Republic involvement
        $isEmisivo = ($originCode === 'DO' && $destinationCode !== 'DO'); // FROM Dominican Republic TO elsewhere
        $isReceptivo = ($originCode !== 'DO' && $destinationCode === 'DO'); // FROM elsewhere TO Dominican Republic

        // Apply convenio logic based on travel direction and quotation type
        if ($isReceptivo) {
            // RECEPTIVO: Traveling TO Dominican Republic (Territorio Nacional)
            return match ($quotationType) {
                'inclusion', 'inclusion_ii' => $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value) ?: '1-EO7PJQQ',
                'cross_selling', 'cross_selling_ii' => $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_II->value) ?: '1-EO7PJQL',
                'stand_alone' => $this->app->get(ConfigurationEnum::CONVENIO_STAND_ALONE->value) ?: '1-EO6M4QZ',
                default => $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_II->value) ?: '1-EO7PJQQ'
            };
        } else {
            // EMISIVO: Traveling FROM Dominican Republic OR international travel (default to EMISIVO)
            return match ($quotationType) {
                'inclusion', 'inclusion_ii' => $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value) ?: '1-EO6M4QP',
                'cross_selling', 'cross_selling_ii' => $this->app->get(ConfigurationEnum::CONVENIO_CROSS_SELLING_I->value) ?: '1-EO6M4QU',
                'stand_alone' => $this->app->get(ConfigurationEnum::CONVENIO_STAND_ALONE->value) ?: '1-EO6M4QZ',
                default => $this->app->get(ConfigurationEnum::CONVENIO_INCLUSION_I->value) ?: '1-EO6M4QP'
            };
        }
    }

    /**
     * Get unique control number suffix for specific quotation type
     */
    public function getControlNumberSuffixForQuotationType(string $type): string
    {
        // Unique suffixes for each quotation type to avoid duplicate control numbers
        $suffixes = [
            'inclusion' => 'I1',              // Inclusión
            'inclusion_ii' => 'I2',           // Inclusión II
            'cross_selling' => 'C1',          // Cross Selling
            'cross_selling_ii' => 'C2',       // Cross Selling II
            'stand_alone' => 'SA',            // Stand Alone
            'default' => 'XX'                 // Fallback
        ];

        return $suffixes[$type] ?? $suffixes['default'];
    }

    /**
     * Create a single quotation with specific country-based convenio logic
     * This method uses the origin and destination country codes from input data to determine the proper convenio
     */
    public function createSingleQuotationWithCountries(array $quotationData, string $quotationType, string $originCountryCode, string $destinationCountryCode, ?\Kanvas\Souk\Orders\Models\Order $order = null, bool $quoteOnly = false): array
    {
        // Generate unique control number for each individual voucher
        // Use combination of order ID, current timestamp and random component for maximum uniqueness
        $baseSequential = $order ? $order->id : (int)(microtime(true) * 1000) % 10000;
        $timestamp = (int)(microtime(true) * 10000) % 100000; // Get timestamp with more precision
        $randomComponent = mt_rand(10, 99); // Add random component for extra uniqueness

        // Create a unique 7-digit sequential number combining all components
        $uniqueSequential = ($baseSequential * 100 + $randomComponent + $timestamp) % 9999999;
        $paddedSequential = str_pad((string)$uniqueSequential, 7, '0', STR_PAD_LEFT);

        $baseControlNumber = 'UA-' . date('Ymd') . '-' . substr($paddedSequential, -7);
        $controlNumber = $baseControlNumber . '-' . $this->getControlNumberSuffixForQuotationType($quotationType);

        // Handle both quotation data (new approach) and voucher data (legacy approach)
        if (isset($quotationData['Edad1'])) {
            // New approach: We received quotation data with ages already calculated
            $leadData = $quotationData;
            $convenio = $quotationData['Convenio'];
        } else {
            // Legacy approach: We received voucher data and need to convert it
            // Set control number and organization
            $quotationData['NroControl'] = $controlNumber;

            // ALWAYS respect the convenio determined by the workflow
            // Check both 'Contrato' and 'contrato' keys for consistency
            $workflowConvenio = $quotationData['Contrato'] ?? $quotationData['contrato'] ?? null;

            if ($workflowConvenio && ! empty($workflowConvenio)) {
                // Use the convenio determined by workflow (variant logic)
                $quotationData['Contrato'] = $workflowConvenio;
                unset($quotationData['contrato']); // Remove lowercase version if it exists for consistency
            } else {
                // If no convenio from workflow, use basic quotation type logic as fallback
                $quotationData['Contrato'] = $this->getConvenioForQuotationType($quotationType);
            }

            if (isset($quotationData['DatosAgencia'])) {
                $quotationData['DatosAgencia']['OrganizacionRegistradora'] = $this->getOrganizationForQuotationType($quotationType);
            }

            // Convert country codes to country names for the quote request
            $originCountryName = $this->countryCodeToName($originCountryCode);
            $destinationName = $this->getDestinationNameFromCountryCode($destinationCountryCode);

            // Create a quote to get detailed product/plan information using extracted countries
            $leadData = $this->convertVoucherDataToLeadDataWithCountries($quotationData, $quotationType, $originCountryName, $destinationName);
            $convenio = $quotationData['Contrato'];
        }

        try {
            // Convert country codes to country names for the result
            $originCountryName = $this->countryCodeToName($originCountryCode);
            $destinationName = $this->getDestinationNameFromCountryCode($destinationCountryCode);

            $quoteResult = $this->createOrUpdateLead($leadData, true);

            $result = [
                'quotation_type' => $quotationType,
                'control_number' => $controlNumber,
                'organization' => $this->getOrganizationForQuotationType($quotationType),
                'convenio' => $convenio, // Use the convenio that was actually used
                'origin_country_code' => $originCountryCode,
                'destination_country_code' => $destinationCountryCode,
                'origin_country_name' => $originCountryName,
                'destination_name' => $destinationName,
                'quote_response' => $quoteResult,      // Detailed quote information
                'response' => $quoteResult             // Main response with all details for Excel
            ];

            // Note: Individual quotations in quote-only mode don't create vouchers
            // Voucher creation will be handled separately with proper voucher data
            if (! $quoteOnly) {
                // For voucher creation, we need proper voucher data structure
                // This would need to be handled differently for individual quotations
                throw new ValidationException("Voucher creation from individual quotations not implemented in this context");
            }

            // Store in order metadata if provided
            if ($order) {
                $this->storeSingleQuotationInOrder($order, $quotationType, $controlNumber, $quoteResult, $originCountryCode, $destinationCountryCode, $convenio);
            }

            return $result;
        } catch (Exception $e) {
            throw new ValidationException("Failed to create {$quotationType} quotation with countries {$originCountryCode}->{$destinationCountryCode}: " . $e->getMessage());
        }
    }

    /**
     * Calculate age from birth date
     */
    protected function calculateAgeFromBirthDate(string $birthDate): string
    {
        try {
            // Parse the birth date (format: MM/DD/YYYY)
            $birth = \DateTime::createFromFormat('m/d/Y', $birthDate);
            if (! $birth) {
                return '28'; // Default age
            }

            $today = new \DateTime();
            $age = $today->diff($birth)->y;

            return (string)$age;
        } catch (Exception $e) {
            return '28'; // Default age in case of error
        }
    }

    /**
     * Store single quotation results in order metadata
     */
    protected function storeSingleQuotationInOrder(\Kanvas\Souk\Orders\Models\Order $order, string $quotationType, string $controlNumber, array $result, string $originCountryCode = 'AR', string $destinationCountryCode = 'DO', ?string $convenio = null): void
    {
        $metadata = $order->metadata ?? [];

        if (! isset($metadata['universalAssistanceData'])) {
            $metadata['universalAssistanceData'] = [];
        }

        if (! isset($metadata['universalAssistanceData']['single_quotations'])) {
            $metadata['universalAssistanceData']['single_quotations'] = [];
        }

        // Use the provided convenio if available, otherwise fall back to the old method
        $convenioToUse = $convenio ?? $this->getConvenioForCountries($originCountryCode, $destinationCountryCode, $quotationType);

        $metadata['universalAssistanceData']['single_quotations'][$quotationType] = [
            'control_number' => $controlNumber,
            'organization' => $this->getOrganizationForQuotationType($quotationType),
            'convenio' => $convenioToUse,
            'origin_country_code' => $originCountryCode,
            'destination_country_code' => $destinationCountryCode,
            'created_at' => now()->toISOString(),
            'response_summary' => [
                'success' => isset($result['response']) || ! empty($result)
            ]
        ];

        $order->metadata = $metadata;
        $order->saveOrFail();
    }

    /**
     * Get SOAP client for Quote (Lead/Quote) operations
     */
    protected function getQuoteClient(): SoapClient
    {
        if ($this->quoteClient === null) {
            try {
                // Download WSDL from S3 to temp file and use locally
                $s3WsdlUrl = 'https://cdn2.kanvas.dev/http___siebel.com_CustomUI_UA Lead Cotizador WS.WSDL';
                $wsdlUrl = $this->downloadWsdlToTemp($s3WsdlUrl, 'lead_cotizador.wsdl');

                // Debug: Log the URL being used

                // Use the local WSDL file for QA testing with correct QA endpoint
                $this->quoteClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'connection_timeout' => 120,
                    // Override the location from WSDL with new QA service endpoint
                    'location' => $this->baseUrl,
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'ciphers' => 'DEFAULT:!DH',
                            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                            'SNI_enabled' => true,
                            'disable_compression' => true,
                        ],
                        'http' => [
                            'timeout' => 120,
                            'user_agent' => 'PHP-SOAP/8.0 Universal-Assistance-Client',
                            'method' => 'POST',
                            'protocol_version' => 1.1,
                            'header' => [
                                'Connection: Keep-Alive',
                                'Cache-Control: no-cache',
                            ],
                        ]
                    ]),
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Quote SOAP client: ' . $e->getMessage());
            }
        }

        return $this->quoteClient;
    }

    /**
     * Get SOAP client for Voucher operations
     */
    protected function getVoucherClient(): SoapClient
    {
        if ($this->voucherClient === null) {
            try {
                // Download WSDL from S3 to temp file and use locally
                $s3WsdlUrl = 'http://cdn2.kanvas.dev/http___siebel.com_CustomUI_UA%20Operaciones%20Voucher%20WS_26SEP2025.WSDL';
                $wsdlUrl = $this->downloadWsdlToTemp($s3WsdlUrl, 'operaciones_voucher.wsdl');


                $this->voucherClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'connection_timeout' => 120,
                    'location' => $this->baseUrl, // Override with QA endpoint
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'ciphers' => 'DEFAULT:!DH',
                            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
                            'SNI_enabled' => true,
                            'disable_compression' => true,
                        ],
                        'http' => [
                            'timeout' => 120,
                            'user_agent' => 'PHP-SOAP/8.0 Universal-Assistance-Client',
                            'method' => 'POST',
                            'protocol_version' => 1.1,
                        ]
                    ]),
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Voucher SOAP client: ' . $e->getMessage());
            }
        }

        return $this->voucherClient;
    }

    /**
     * Get SOAP client for Query operations
     */
    protected function getQueryClient(): SoapClient
    {
        if ($this->queryClient === null) {
            try {
                // Download WSDL from S3 to temp file and use locally
                $s3WsdlUrl = 'https://cdn2.kanvas.dev/http___siebel.com_CustomUI_UA QueryVoucherPortal WS.WSDL';
                $wsdlUrl = $this->downloadWsdlToTemp($s3WsdlUrl, 'query_voucher_portal.wsdl');


                $this->queryClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'connection_timeout' => 60,
                    'location' => $this->baseUrl, // Override with QA endpoint
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'ciphers' => 'DEFAULT:!DH',
                        ],
                        'http' => [
                            'timeout' => 60,
                            'user_agent' => 'PHP-SOAP/7.4',
                        ]
                    ]),
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create Query SOAP client: ' . $e->getMessage());
            }
        }

        return $this->queryClient;
    }

    /**
     * Filter voucher query response to extract only essential data
     */
    protected function filterVoucherQueryResponse(array $queryResponse): array
    {
        // Extract only essential data from the complete query response
        $filtered = [];

        // Main voucher info
        if (isset($queryResponse['ListaVoucher'])) {
            $vouchers = is_array($queryResponse['ListaVoucher']) ? $queryResponse['ListaVoucher'] : [$queryResponse['ListaVoucher']];

            foreach ($vouchers as $index => $voucher) {
                $filtered['vouchers'][$index] = [
                    'voucher_number' => $voucher['IdVoucher'] ?? null,
                    'policy_number' => $voucher['NumeroPoliza'] ?? null,
                    'status' => $voucher['Estado'] ?? null,
                    'activation_date' => $voucher['FechaActivacion'] ?? null,
                    'expiration_date' => $voucher['FechaExpiracion'] ?? null,
                    'issue_date' => $voucher['FechaEmision'] ?? null,
                    'amount' => $voucher['Monto'] ?? null,
                    'currency' => $voucher['Moneda'] ?? null,
                    'plan_name' => $voucher['NombrePlan'] ?? null,
                    'destination' => $voucher['Destino'] ?? null,
                    'product_name' => $voucher['NombreProducto'] ?? null,

                    // Contact information
                    'holder_name' => $voucher['NombreTitular'] ?? null,
                    'holder_document' => $voucher['DocumentoTitular'] ?? null,
                    'holder_email' => $voucher['EmailTitular'] ?? null,
                    'holder_phone' => $voucher['TelefonoTitular'] ?? null,

                    // Beneficiary information (key data only)
                    'beneficiaries_count' => isset($voucher['ListaBeneficiarios']) ? count(is_array($voucher['ListaBeneficiarios']) ? $voucher['ListaBeneficiarios'] : [$voucher['ListaBeneficiarios']]) : 0,

                    // Coverage summary (essential amounts only)
                    'coverage_medical' => $voucher['CoberturaMedica'] ?? null,
                    'coverage_baggage' => $voucher['CoberturaEquipaje'] ?? null,
                    'coverage_cancellation' => $voucher['CoberturaCancelacion'] ?? null,
                ];

                // Add essential beneficiary data (name and document only)
                if (isset($voucher['ListaBeneficiarios'])) {
                    $beneficiaries = is_array($voucher['ListaBeneficiarios']) ? $voucher['ListaBeneficiarios'] : [$voucher['ListaBeneficiarios']];
                    $filtered['vouchers'][$index]['beneficiaries'] = [];

                    foreach ($beneficiaries as $benIndex => $beneficiary) {
                        $filtered['vouchers'][$index]['beneficiaries'][$benIndex] = [
                            'name' => ($beneficiary['Nombre'] ?? '') . ' ' . ($beneficiary['Apellido'] ?? ''),
                            'document' => $beneficiary['Documento'] ?? null,
                            'birth_date' => $beneficiary['FechaNacimiento'] ?? null,
                            'age' => $beneficiary['Edad'] ?? null,
                        ];
                    }
                }
            }
        }

        // General response info
        $filtered['query_info'] = [
            'organization' => $queryResponse['Organizacion'] ?? null,
            'query_date' => date('Y-m-d H:i:s'),
            'success' => ! isset($queryResponse['ErrorCode']) || $queryResponse['ErrorCode'] !== '01',
            'error_code' => $queryResponse['ErrorCode'] ?? null,
            'error_description' => $queryResponse['ErrorDescription'] ?? null,
        ];

        return $filtered;
    }

    /**
     * Test connectivity to the service
     */
    public function testConnection(): bool
    {
        try {
            $client = $this->getQuoteClient();

            // Try to get available functions (will fail if service is not accessible)
            $functions = $client->__getFunctions();

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if the SOAP response contains ErrorCode '01' (no products)
     */
    protected function hasErrorCode01(array $response): bool
    {
        try {
            // Recursively check for ErrorCode '01' anywhere in the response structure
            $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($response));
            foreach ($iterator as $key => $value) {
                if ($key === 'ErrorCode' && (string)$value === '01') {
                    return true;
                }
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create or update a lead (quote)
     */
    public function createOrUpdateLead(array $leadData, bool $useRawData = false): array
    {
        try {
            $client = $this->getQuoteClient();

            // Debug: Get available functions
            try {
                $functions = $client->__getFunctions();
            } catch (Exception $debugEx) {
            }

            // Create the exact structure from your working QA SOAP request
            if ($useRawData) {
                // Use raw QA data without transformation for testing
                $parameters = [
                    'UALeadCotizadorReq' => [
                        'DatosLeadCotizadorIn' => $leadData
                    ]
                ];
            } else {
                // Use standard field mapping
                $parameters = [
                    'UALeadCotizadorReq' => [
                        'DatosLeadCotizadorIn' => [
                            'IdLead' => $leadData['idLead'] ?? $leadData['IdLead'] ?? '',
                            'OrganizacionEmisora' => $leadData['organizacionEmisora'] ?? $this->organization,
                            'CantCotizaciones' => $leadData['cantCotizaciones'] ?? $leadData['CantCotizaciones'] ?? 1,
                            'Convenio' => $leadData['convenio'] ?? $leadData['Convenio'] ?? '',
                            'Folleto' => $leadData['folleto'] ?? $leadData['Folleto'] ?? '',
                            'PaisOrigen' => $leadData['paisOrigen'] ?? $leadData['PaisOrigen'] ?? '',
                            'Destino' => $leadData['destino'] ?? $leadData['Destino'] ?? '',
                            'TipoViaje' => $leadData['tipoViaje'] ?? $leadData['TipoViaje'] ?? '',
                            'FechaInicio' => $leadData['fechaInicio'] ?? $leadData['FechaInicio'] ?? '',
                            'FechaFin' => $leadData['fechaFin'] ?? $leadData['FechaFin'] ?? '',
                            'CantidadPasajeros' => $leadData['cantidadPasajeros'] ?? $leadData['CantidadPasajeros'] ?? 1,
                            'PackFamiliar' => $leadData['packFamiliar'] ?? $leadData['PackFamiliar'] ?? '',
                            'Edad1' => $leadData['edad1'] ?? $leadData['Edad1'] ?? '',
                            'Edad2' => $leadData['edad2'] ?? $leadData['Edad2'] ?? '',
                            'Edad3' => $leadData['edad3'] ?? $leadData['Edad3'] ?? '',
                            'Edad4' => $leadData['edad4'] ?? $leadData['Edad4'] ?? '',
                            'Edad5' => $leadData['edad5'] ?? $leadData['Edad5'] ?? '',
                            'Edad6' => $leadData['edad6'] ?? $leadData['Edad6'] ?? '',
                            'Edad7' => $leadData['edad7'] ?? $leadData['Edad7'] ?? '',
                            'Edad8' => $leadData['edad8'] ?? $leadData['Edad8'] ?? '',
                            'Edad9' => $leadData['edad9'] ?? $leadData['Edad9'] ?? '',
                            'Edad10' => $leadData['edad10'] ?? $leadData['Edad10'] ?? '',
                            'Categoria' => $leadData['categoria'] ?? $leadData['Categoria'] ?? '',
                            'Precompras' => $leadData['precompras'] ?? $leadData['Precompras'] ?? '',
                        ]
                    ]
                ];
            }

            // Debug: Log the input data and organization

            // Set SOAP headers for authentication - Exact format from working QA example
            $client->__setSoapHeaders(null); // Clear any existing headers

            // Create the complete Security header structure that matches the working example
            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            // Create header using SoapVar with the complete Security element
            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);


            $response = $client->__soapCall('LeadCotizadorOper', [$parameters]);

            // Include the actual input data that was sent to the SOAP service
            $result = (array) $response;
            $result['quotation_input_data'] = $parameters; // Add the exact input data sent to SOAP

            // Return the response with input data included
            return $result;
        } catch (SoapFault $e) {
            throw new ValidationException('SOAP Fault in create/update lead: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Failed to create/update lead: ' . $e->getMessage());
        }
    }

    /**
     * Create a voucher
     */
    public function createVoucher(array $voucherData, bool $useRawData = false): array
    {
        try {
            $client = $this->getVoucherClient();

            // Based on the WSDL, the method is Alta_Voucher_Operation and expects UAAltaVoucheMinRequest structure
            if ($useRawData) {
                $parameters = [
                    'UAAltaVoucheMinRequest' => [
                        'DatosVoucher' => $voucherData
                    ]
                ];

                // Debug: Log the exact parameters being sent
            } else {
                // Standard field mapping for voucher creation - Updated based on successful test structure
                $parameters = [
                    'UAAltaVoucheMinRequest' => [
                        'DatosVoucher' => [
                            // Main voucher fields - following successful QA example order
                            'NroControl' => $voucherData['NroControl'] ?? $voucherData['nroControl'] ?? 'CTRL-PHP-' . substr((string)time(), -3),
                            'Vendedor' => $voucherData['vendedor'] ?? $voucherData['Vendedor'] ?? 'WSSIMLIMITEDO', // Use working QA username as default
                            'FechaEmision' => $voucherData['fechaEmision'] ?? date('m/d/Y'),
                            'Destino' => $voucherData['destino'] ?? 'Centro america/Caribe', // Use valid destination
                            'FechaVigencia' => $voucherData['fechaVigencia'] ?? date('m/d/Y', strtotime('+120 days')),
                            'FechaFinal' => $voucherData['fechaFinal'] ?? date('m/d/Y', strtotime('+120 days')),
                            'MonedaLista' => $voucherData['monedaLista'] ?? 'USD',
                            'Precio' => '0.00',
                            'NombreContactoVoucher' => $voucherData['nombreContactoVoucher'] ?? '',
                            'NroTelContactoVoucher' => $voucherData['nroTelContactoVoucher'] ?? '',
                            'Canal' => $voucherData['canal'] ?? 'Turismo', // Use 'Turismo' instead of 'WEB'
                            'TipoVenta' => $voucherData['tipoVenta'] ?? 'Anual', // Annual sale type
                            'Linea' => $voucherData['linea'] ?? 'Salud', // Health line for travel assistance
                            'EstadoVoucher' => $voucherData['estadoVoucher'] ?? 'Activo', // Active status
                            'MotivoVoucher' => $voucherData['motivoVoucher'] ?? 'Individual', // Individual voucher
                            'Facturacion' => $voucherData['facturacion'] ?? 'Pendiente Facturación', // Pending billing
                            'Contrato' => $voucherData['Contrato'] ?? '1-DEY2E2H',
                            'LeadId' => $voucherData['leadId'] ?? $voucherData['idLead'] ?? '',
                            'EnvioVoucherMail' => $voucherData['envioVoucherMail'] ?? 'Y',
                            'Tarifa' => 'N', // Always N - no fallback needed
                            'PostProcesoFlag' => 'N', // Always N - no fallback needed

                            // Sub-structures in successful order
                            'DatosAgencia' => $voucherData['datosAgencia'] ?? [
                                'OrganizacionRegistradora' => $this->organization,
                            ],

                            'DatosProducto' => $voucherData['datosProducto'] ?? [
                                'NombreProducto' => $voucherData['nombreProducto'] ?? 'ARG MAXIMUM FOLL 2021 VP3',
                            ],

                            'DatosSolicitante' => $voucherData['datosSolicitante'] ?? [
                                // Add NroPolizaSeguro as required by WSDL but use empty value like working test
                                'NroPolizaSeguro' => $voucherData['nroPolizaSeguro'] ?? '',
                                'NombreSolicitante' => $voucherData['nombreSolicitante'] ?? 'Test',
                                'ApellidoSolicitante' => $voucherData['apellidoSolicitante'] ?? 'User',
                                'TipoDocumentoSolicitante' => $voucherData['tipoDocumentoSolicitante'] ?? 'DNI',
                                'NroDocumentoSolicitante' => $voucherData['nroDocumentoSolicitante'] ?? '12345678',
                                'PaisResidenciaSolicitante' => $voucherData['paisResidenciaSolicitante'] ?? 'ARGENTINA',
                                'SexoSolicitante' => $voucherData['sexoSolicitante'] ?? 'M', // M or F
                                'FechaNacimientoSolicitante' => $voucherData['fechaNacimientoSolicitante'] ?? '01/01/1990',
                                'CorreoElectronicoSolicitante' => $voucherData['correoElectronicoSolicitante'] ?? 'test@test.com',
                                'TituloCortesiaSolicitante' => $voucherData['tituloCortesiaSolicitante'] ?? 'Sr.', // Courtesy title
                            ],
                        ]
                    ]
                ];
            }

            // Set SOAP headers for authentication
            $client->__setSoapHeaders(null);

            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);

            $response = $client->__soapCall('Alta_Voucher_Operation', [$parameters]);

            // Include the actual input data that was sent to the SOAP service
            $result = (array) $response;
            $result['voucher_input_data'] = $parameters; // Add the exact input data sent to SOAP

            return $result;
        } catch (Exception $e) {
            throw new ValidationException('Failed to create voucher: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a lead
     */
    public function cancelLead(string $leadId, string $reasonCode): array
    {
        try {
            $client = $this->getQuoteClient();

            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                'IdLead' => $leadId,
                'ReasonCode' => $reasonCode
            ];

            $response = $client->__soapCall('LeadServiceRetireLead', [$parameters]);

            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to cancel lead: ' . $e->getMessage());
        }
    }

    /**
     * Query voucher information
     */
    public function queryVoucher(array $queryParams, bool $useRawData = false): array
    {
        try {
            $client = $this->getQueryClient();

            // Based on the WSDL, the method is QueryVoucherPortalOper and expects simple parameters
            if ($useRawData) {
                // Use raw data directly as provided (for testing) - mantener lógica original
                $parameters = $queryParams;
            } else {
                // Standard field mapping for voucher query - WSDL only requires VoucherNumber and Organization
                $parameters = [
                    'VoucherNumber' => $queryParams['VoucherNumber'] ?? $queryParams['voucherNumber'] ?? $queryParams['idVoucher'] ?? '',
                    'Organization' => $queryParams['Organization'] ?? $this->organization
                ];
            }


            // Set SOAP headers for authentication
            $client->__setSoapHeaders(null);

            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);


            // Call the correct SOAP method according to WSDL - use single parameter object like other methods
            $response = $client->QueryVoucherPortalOper($parameters);


            return (array) $response;
        } catch (\SoapFault $e) {
            throw new ValidationException('SOAP Fault in query voucher: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Failed to query voucher: ' . $e->getMessage());
        }
    }

    /**
     * Generate PDF
     */
    public function generatePdf(array $pdfParams): array
    {
        try {
            $client = $this->getQueryClient();

            $parameters = [
                'Username' => $this->username,
                'Password' => $this->password,
                'OrganizacionEmisora' => $this->organization,
                ...$pdfParams
            ];

            $response = $client->__soapCall('SendReportOper', [$parameters]);

            return $this->parseSoapResponse($response);
        } catch (Exception $e) {
            throw new ValidationException('Failed to generate PDF: ' . $e->getMessage());
        }
    }

    /**
     * Parse SOAP response to array
     */
    protected function parseSoapResponse($response): array
    {
        if (is_object($response)) {
            return json_decode(json_encode($response), true);
        }

        return (array) $response;
    }

    /**
     * Get the last SOAP request for debugging
     */
    public function getLastRequest(string $clientType = 'quote'): ?string
    {
        switch ($clientType) {
            case 'quote':
                return $this->quoteClient?->__getLastRequest();
            case 'voucher':
                return $this->voucherClient?->__getLastRequest();
            case 'query':
                return $this->queryClient?->__getLastRequest();
            default:
                return null;
        }
    }

    /**
     * Send report request (PDF generation from voucher)
     */
    public function sendReport(array $reportData, bool $useRawData = false): array
    {
        try {
            $client = $this->getSendReportClient();

            // Based on the WSDL, the method is SendReportOper and expects simple parameters
            if ($useRawData) {
                $parameters = $reportData;
            } else {
                // Standard field mapping for send report - WSDL requires Language, VoucherNumber, Tarifa, Organization
                $parameters = [
                    'Language' => $reportData['Language'] ?? $reportData['language'] ?? 'Spanish',
                    'VoucherNumber' => $reportData['VoucherNumber'] ?? $reportData['voucherNumber'] ?? '',
                    'Tarifa' => 'N', // Always N - no PDF generation
                    'Organization' => $reportData['Organization'] ?? $this->organization
                ];
            }

            // Debug logging

            // Set SOAP headers for authentication
            $client->__setSoapHeaders(null);

            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);

            // Debug the SOAP call

            // Based on WSDL structure, SendReport expects individual parameters, not wrapped in an array
            // The method signature is: SendReportOper(Language, VoucherNumber, Tarifa, Organization)
            $response = $client->SendReportOper(
                $parameters['Language'],
                $parameters['VoucherNumber'],
                $parameters['Tarifa'],
                $parameters['Organization']
            );


            return (array) $response;
        } catch (\SoapFault $e) {
            throw new ValidationException('SOAP Fault in send report: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Failed to send report: ' . $e->getMessage());
        }
    }

    /**
     * Get SOAP client for SendReport operations
     */
    protected function getSendReportClient(): SoapClient
    {
        if (! isset($this->sendReportClient)) {
            try {
                // Download WSDL from S3 to temp file and use locally
                $s3WsdlUrl = 'https://cdn2.kanvas.dev/http___siebel.com_CustomUI_UA SendReport WS.WSDL';
                $wsdlUrl = $this->downloadWsdlToTemp($s3WsdlUrl, 'send_report.wsdl');

                // Debug: Log the URL being used

                $this->sendReportClient = new SoapClient($wsdlUrl, [
                    'trace' => true,
                    'exceptions' => true,
                    'soap_version' => SOAP_1_1,
                    'cache_wsdl' => WSDL_CACHE_NONE,
                    'connection_timeout' => 60,
                    'location' => $this->baseUrl, // Override with QA endpoint
                    'stream_context' => stream_context_create([
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true,
                            'ciphers' => 'DEFAULT:!DH',
                        ],
                        'http' => [
                            'timeout' => 60,
                            'user_agent' => 'PHP-SOAP/7.4',
                        ]
                    ]),
                ]);
            } catch (SoapFault $e) {
                throw new ValidationException('Failed to create SendReport SOAP client: ' . $e->getMessage());
            }
        }

        return $this->sendReportClient;
    }

    /**
     * Consulta voucher information (using Operaciones Voucher WS)
     */
    public function consultaVoucher(array $consultaData, bool $useRawData = false): array
    {
        try {
            $client = $this->getVoucherClient();

            // Based on the WSDL, the method is Consulta_Voucher_Operation
            if ($useRawData) {
                $parameters = $consultaData;
            } else {
                // Standard field mapping for consulta voucher - using proper WSDL structure
                $parameters = [
                    'UAConsultaVoucherRequest' => [
                        'ConsultaDatosVoucherReq' => [
                            'OrganizacionRegistradoraConsulta' => $consultaData['agencia'] ?? $this->organization,
                            'NroControlConsulta' => $consultaData['voucherNumber'] ?? '',
                        ]
                    ]
                ];
            }


            // Set SOAP headers for authentication
            $client->__setSoapHeaders(null);

            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);


            // Call the correct SOAP method according to WSDL with proper structure
            $response = $client->Consulta_Voucher_Operation($parameters);


            return (array) $response;
        } catch (\SoapFault $e) {
            throw new ValidationException('SOAP Fault in consulta voucher: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Failed to consulta voucher: ' . $e->getMessage());
        }
    }

    /**
     * Anula voucher (cancel voucher using Operaciones Voucher WS)
     */
    public function anulaVoucher(array $anulaData, bool $useRawData = false): array
    {
        try {
            $client = $this->getVoucherClient();

            // Based on the WSDL, the method is Anula_Voucher_Operation
            if ($useRawData) {
                $parameters = $anulaData;
            } else {
                // Standard field mapping for anula voucher
                $parameters = [
                    'AgenciaAnulacion' => $anulaData['AgenciaAnulacion'] ?? $anulaData['agencia'] ?? $this->organization,
                    'NroVoucherSiebelAnulacion' => $anulaData['NroVoucherSiebelAnulacion'] ?? $anulaData['voucherNumber'] ?? '',
                ];
            }


            // Set SOAP headers for authentication
            $client->__setSoapHeaders(null);

            $securityXML = '<wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/07/secext">
                <wsse:UsernameToken xmlns:wsu="http://schemas.xmlsoap.org/ws/2002/07/utility">
                    <wsse:Username>' . htmlspecialchars($this->username) . '</wsse:Username>
                    <wsse:Password Type="wsse:PasswordText">' . htmlspecialchars($this->password) . '</wsse:Password>
                </wsse:UsernameToken>
            </wsse:Security>';

            $securityHeader = new \SoapHeader(
                'http://schemas.xmlsoap.org/ws/2002/07/secext',
                'Security',
                new \SoapVar($securityXML, XSD_ANYXML)
            );

            $client->__setSoapHeaders([$securityHeader]);


            // Call the correct SOAP method according to WSDL with single parameter object like consulta
            $response = $client->Anula_Voucher_Operation($parameters);


            return (array) $response;
        } catch (\SoapFault $e) {
            throw new ValidationException('SOAP Fault in anula voucher: ' . $e->getMessage());
        } catch (Exception $e) {
            throw new ValidationException('Failed to anula voucher: ' . $e->getMessage());
        }
    }

    /**
     * Get the last SOAP response for debugging
     */
    public function getLastResponse(string $clientType = 'quote'): ?string
    {
        switch ($clientType) {
            case 'quote':
                return $this->quoteClient?->__getLastResponse();
            case 'voucher':
            case 'consulta':
            case 'anula':
                return $this->voucherClient?->__getLastResponse();
            case 'query':
                return $this->queryClient?->__getLastResponse();
            case 'sendreport':
                return $this->sendReportClient?->__getLastResponse();
            default:
                return null;
        }
    }

    /**
     * Extracts product summary from a Universal Assistance quote response.
     * Returns an array of products with: producto, nombre_producto, id_producto, id_lead_out, precio_emision, moneda_lista.
     * Suitable for use in a GraphQL endpoint.
     *
     * @param array $quoteResponse The decoded response from UA (array, not JSON string)
     * @return array[]
     */
    public static function extractQuoteProductsSummary(array $quoteResponse): array
    {
        $result = [];
        if (! isset($quoteResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'])) {
            return $result;
        }
        $products = $quoteResponse['UALeadCotizadorResp']['DatosLeadCotizadorOut'];
        // Ensure $products is always an array
        if (isset($products['IdProducto'])) {
            $products = [$products];
        }
        foreach ($products as $product) {
            $result[] = [
                'producto' => $product['Producto'] ?? '',
                'nombre_producto' => $product['NombreProducto'] ?? '',
                'id_producto' => $product['IdProducto'] ?? '',
                'id_lead_out' => $product['IdLeadOut'] ?? '',
                'precio_emision' => $product['PrecioEmision'] ?? '',
                'moneda_lista' => $product['MonedaLista'] ?? '',
            ];
        }
        return $result;
    }

    /**
     * Extract origin country code from voucher data
     */
    protected function extractOriginCountryCode(array $voucherData): string
    {
        // Try to get origin country from DatosSolicitante (person's residence country)
        if (isset($voucherData['DatosSolicitante']['PaisResidenciaSolicitante'])) {
            $countryName = $voucherData['DatosSolicitante']['PaisResidenciaSolicitante'];
            return $this->countryNameToCode($countryName);
        }

        // Default to AR (Argentina) if not found
        return 'AR';
    }

    /**
     * Extract destination country code from voucher data
     */
    protected function extractDestinationCountryCode(array $voucherData): string
    {
        // Check the destination field in voucher data
        if (isset($voucherData['Destino'])) {
            $destination = $voucherData['Destino'];

            // Map destinations to country codes based on the table logic
            return match ($destination) {
                'Territorio Nacional' => 'DO',
                'Centro america/Caribe' => 'PA', // Representative country for region
                'America del norte' => 'US',
                'América del Sur (salvo Vzla)' => 'AR',
                'Europa' => 'ES',
                'Asia' => 'JP',
                'Africa' => 'ZA',
                'Oceania' => 'AU',
                default => 'DO' // Default to DO
            };
        }

        // Default to DO if not found
        return 'DO';
    }

    /**
     * Convert country name to country code (reverse mapping)
     */
    protected function countryNameToCode(string $countryName): string
    {
        $nameToCode = [
            'ARGENTINA' => 'AR',
            'REPUBLICA DOMINICANA' => 'DO',
            'ESTADOS UNIDOS' => 'US',
            'CANADA' => 'CA',
            'MEXICO' => 'MX',
            'ESPAÑA' => 'ES',
            'FRANCIA' => 'FR',
            'ITALIA' => 'IT',
            'BRASIL' => 'BR',
            'COLOMBIA' => 'CO',
        ];

        $normalizedName = strtoupper($countryName);
        return $nameToCode[$normalizedName] ?? 'AR'; // Default to AR
    }

    /**
     * Convert country code to country name for Universal Assistance
     */
    protected function countryCodeToName(string $countryCode): string
    {
        $codeToName = [
            'AR' => 'ARGENTINA',
            'DO' => 'REPUBLICA DOMINICANA',
            'US' => 'USA',
            'CO' => 'COLOMBIA',
            'MX' => 'MEXICO',
            'PE' => 'PERU',
            'CL' => 'CHILE',
            'VE' => 'VENEZUELA',
            'EC' => 'ECUADOR',
            'UY' => 'URUGUAY',
            'PY' => 'PARAGUAY',
            'BO' => 'BOLIVIA',
            'BR' => 'BRASIL',
            'CR' => 'COSTA RICA',
            'PA' => 'PANAMA',
            'GT' => 'GUATEMALA',
            'HN' => 'HONDURAS',
            'NI' => 'NICARAGUA',
            'SV' => 'EL SALVADOR',
            'BZ' => 'BELICE',
            'JM' => 'JAMAICA',
            'CU' => 'CUBA',
            'HT' => 'HAITI',
            'PR' => 'PUERTO RICO',
            'TT' => 'TRINIDAD Y TOBAGO',
            'BB' => 'BARBADOS',
            'GD' => 'GRANADA',
            'LC' => 'SANTA LUCIA',
            'VC' => 'SAN VICENTE',
            'AG' => 'ANTIGUA Y BARBUDA',
            'DM' => 'DOMINICA',
            'KN' => 'SAN CRISTOBAL',
            'AW' => 'ARUBA',
            'CW' => 'CURACAO',
            'BQ' => 'BONAIRE',
            'SX' => 'SINT MAARTEN',
            'MF' => 'SAN MARTIN',
            'GP' => 'GUADALUPE',
            'MQ' => 'MARTINICA',
            'GF' => 'GUAYANA FRANCESA',
            'SR' => 'SURINAM',
            'GY' => 'GUYANA',
            'ES' => 'ESPAÑA',
            'FR' => 'FRANCIA',
            'IT' => 'ITALIA',
            'DE' => 'ALEMANIA',
            'GB' => 'REINO UNIDO',
            'PT' => 'PORTUGAL',
            'TR' => 'TURQUIA',
        ];

        return $codeToName[strtoupper($countryCode)] ?? 'ARGENTINA'; // Default to ARGENTINA
    }

    /**
     * Convert destination country code to destination name for Universal Assistance
     */
    protected function getDestinationNameFromCountryCode(string $countryCode): string
    {
        // Map country codes to Universal Assistance valid destinations
        // Valid destinations: Africa, America del norte, América del Sur (salvo Vzla), Asia, Centro america/Caribe, Europa, Oceanía, Territorio Nacional
        $countryToDestination = [
            // Territorio Nacional (Dominican Republic)
            'DO' => 'Territorio Nacional',

            // Centro america/Caribe
            'CR' => 'Centro america/Caribe',
            'PA' => 'Centro america/Caribe',
            'GT' => 'Centro america/Caribe',
            'HN' => 'Centro america/Caribe',
            'NI' => 'Centro america/Caribe',
            'SV' => 'Centro america/Caribe',
            'BZ' => 'Centro america/Caribe',
            'JM' => 'Centro america/Caribe',
            'CU' => 'Centro america/Caribe',
            'HT' => 'Centro america/Caribe',
            'PR' => 'Centro america/Caribe',
            'TT' => 'Centro america/Caribe',
            'BB' => 'Centro america/Caribe',
            'GD' => 'Centro america/Caribe',
            'LC' => 'Centro america/Caribe',
            'VC' => 'Centro america/Caribe',
            'AG' => 'Centro america/Caribe',
            'DM' => 'Centro america/Caribe',
            'KN' => 'Centro america/Caribe',
            'AW' => 'Centro america/Caribe',
            'CW' => 'Centro america/Caribe',
            'BQ' => 'Centro america/Caribe',
            'SX' => 'Centro america/Caribe',
            'MF' => 'Centro america/Caribe',
            'GP' => 'Centro america/Caribe',
            'MQ' => 'Centro america/Caribe',

            // America del norte
            'US' => 'America del norte',
            'CA' => 'America del norte',
            'MX' => 'America del norte',

            // América del Sur (salvo Vzla)
            'AR' => 'América del Sur (salvo Vzla)',
            'BR' => 'América del Sur (salvo Vzla)',
            'CO' => 'América del Sur (salvo Vzla)',
            'PE' => 'América del Sur (salvo Vzla)',
            'CL' => 'América del Sur (salvo Vzla)',
            'EC' => 'América del Sur (salvo Vzla)',
            'UY' => 'América del Sur (salvo Vzla)',
            'PY' => 'América del Sur (salvo Vzla)',
            'BO' => 'América del Sur (salvo Vzla)',
            'GY' => 'América del Sur (salvo Vzla)',
            'SR' => 'América del Sur (salvo Vzla)',
            'GF' => 'América del Sur (salvo Vzla)',

            // Europa
            'ES' => 'Europa',
            'FR' => 'Europa',
            'IT' => 'Europa',
            'DE' => 'Europa',
            'GB' => 'Europa',
            'PT' => 'Europa',
            'TR' => 'Europa',
            'NL' => 'Europa',
            'BE' => 'Europa',
            'CH' => 'Europa',
            'AT' => 'Europa',
            'GR' => 'Europa',
            'NO' => 'Europa',
            'SE' => 'Europa',
            'DK' => 'Europa',
            'FI' => 'Europa',
            'IE' => 'Europa',
            'PL' => 'Europa',
            'CZ' => 'Europa',
            'HU' => 'Europa',
            'RO' => 'Europa',
            'BG' => 'Europa',
            'HR' => 'Europa',
            'SI' => 'Europa',
            'SK' => 'Europa',
            'EE' => 'Europa',
            'LV' => 'Europa',
            'LT' => 'Europa',
            'MT' => 'Europa',
            'CY' => 'Europa',
            'LU' => 'Europa',
        ];

        return $countryToDestination[strtoupper($countryCode)] ?? 'Centro america/Caribe'; // Default to Centro america/Caribe
    }

    /**
     * Convert voucher data to lead data format with specific countries
     * Always preserves the quotation data structure from buildGroupQuotationData
     * CRITICAL: This method preserves ALL group data including CantidadPasajeros and all Edad1-Edad10 fields
     */
    protected function convertVoucherDataToLeadDataWithCountries(array $voucherData, string $quotationType, string $originCountryName, string $destinationName): array
    {
        // CRITICAL: Always preserve the quotation data structure that comes from buildGroupQuotationData
        // This ensures family groups maintain ALL person data (ages, count) for proper Universal Assistance pricing
        // Whether it's 1 person or 10 people, this structure must be preserved exactly as built


        $leadData = [
            'IdLead' => $voucherData['IdLead'] ?? '',
            'OrganizacionEmisora' => $voucherData['OrganizacionEmisora'] ?? $this->getOrganizationForQuotationType($quotationType),
            'CantCotizaciones' => $voucherData['CantCotizaciones'] ?? 1,
            'Convenio' => $voucherData['Convenio'] ?? $voucherData['Contrato'] ?? '',
            'Folleto' => $voucherData['Folleto'] ?? '',
            'PaisOrigen' => $voucherData['PaisOrigen'] ?? $originCountryName,
            'Destino' => $voucherData['Destino'] ?? $destinationName,
            'TipoViaje' => $voucherData['TipoViaje'] ?? 'Un viaje',
            'FechaInicio' => $voucherData['FechaInicio'] ?? date('m/d/Y'),
            'FechaFin' => $voucherData['FechaFin'] ?? date('m/d/Y', strtotime('+7 days')),
            'CantidadPasajeros' => $voucherData['CantidadPasajeros'] ?? 1,
            'PackFamiliar' => $voucherData['PackFamiliar'] ?? '',
            // Preserve all ages from the quotation data
            'Edad1' => $voucherData['Edad1'] ?? '',
            'Edad2' => $voucherData['Edad2'] ?? '',
            'Edad3' => $voucherData['Edad3'] ?? '',
            'Edad4' => $voucherData['Edad4'] ?? '',
            'Edad5' => $voucherData['Edad5'] ?? '',
            'Edad6' => $voucherData['Edad6'] ?? '',
            'Edad7' => $voucherData['Edad7'] ?? '',
            'Edad8' => $voucherData['Edad8'] ?? '',
            'Edad9' => $voucherData['Edad9'] ?? '',
            'Edad10' => $voucherData['Edad10'] ?? '',
            'Categoria' => $voucherData['Categoria'] ?? '',
            'Precompras' => $voucherData['Precompras'] ?? '',
        ];

        return $leadData;
    }
}
