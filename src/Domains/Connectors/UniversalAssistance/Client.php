<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
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

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        // Get configuration from app settings with QA fallbacks
        $this->baseUrl = (string)($app->get(ConfigurationEnum::BASE_URL->value) ?: 'https://wssimlimitedo.apiqa.universal-assistance.com:8443/siebel/app/eai_anon/esn?SWEExtSource=SecureWebService&SWEExtCmd=Execute');
        $this->username = (string)($app->get(ConfigurationEnum::USERNAME->value) ?: 'WSSIMLIMITEDO');
        $this->password = (string)($app->get(ConfigurationEnum::PASSWORD->value) ?: 'Wss1ml1m1t3d0*QA');
        $this->organization = (string)($app->get(ConfigurationEnum::ORGANIZATION->value) ?: '1-ENYNUF7');
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

        if (! isset($metadata['universal_assistance'])) {
            $metadata['universal_assistance'] = [];
        }

        $metadata['universal_assistance']['control_numbers'] = [
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
     * According to QA table, OrganizationRegistradora is always 1-ENYNUF7
     */
    public function getOrganizationForQuotationType(string $type): string
    {
        // OrganizationRegistradora QA is always the same for all quotation types
        return '1-ENYNUF7';
    }

    /**
     * Get convenio (contract) code for specific quotation type
     * This is what actually changes between quotation types
     */
    public function getConvenioForQuotationType(string $type): string
    {
        // QA Environment credentials - specific convenios for each quotation type
        $convenios = [
            'inclusion' => '1-EO6M4QP',        // Inclusión quotations
            'inclusion_ii' => '1-EO7PIQQ',     // Inclusión II quotations
            'cross_selling' => '1-EO6M4QU',    // Cross Selling quotations
            'cross_selling_ii' => '1-EO7PIQL', // Cross Selling II quotations
            'stand_alone' => '1-EO6M4QZ',      // Stand Alone quotations
            'default' => '1-EO6M4QP'           // Fallback to Inclusión convenio
        ];

        return $convenios[$type] ?? $convenios['default'];
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
     * Get all available quotation types with their organizations and convenios
     */
    public function getAllQuotationTypes(): array
    {
        return [
            'inclusion' => [
                'code' => 'inclusion',
                'name' => 'Inclusión',
                'organization' => '1-ENYNUF7',  // OrganizationRegistradora QA
                'convenio' => '1-EO6M4QP',     // Convenio QA
                'description' => 'Base quotation - Inclusión standard'
            ],
            'inclusion_ii' => [
                'code' => 'inclusion_ii',
                'name' => 'Inclusión II',
                'organization' => '1-ENYNUF7',  // OrganizationRegistradora QA
                'convenio' => '1-EO7PIQQ',     // Convenio QA
                'description' => 'Enhanced base quotation - Inclusión II'
            ],
            'cross_selling' => [
                'code' => 'cross_selling',
                'name' => 'Cross Selling',
                'organization' => '1-ENYNUF7',  // OrganizationRegistradora QA
                'convenio' => '1-EO6M4QU',     // Convenio QA
                'description' => 'Additional offer - Cross Selling standard'
            ],
            'cross_selling_ii' => [
                'code' => 'cross_selling_ii',
                'name' => 'Cross Selling II',
                'organization' => '1-ENYNUF7',  // OrganizationRegistradora QA
                'convenio' => '1-EO7PIQL',     // Convenio QA
                'description' => 'Enhanced additional offer - Cross Selling II'
            ],
            'stand_alone' => [
                'code' => 'stand_alone',
                'name' => 'Stand Alone',
                'organization' => '1-ENYNUF7',  // OrganizationRegistradora QA
                'convenio' => '1-EO6M4QZ',     // Convenio QA
                'description' => 'Independent quotation - Stand Alone'
            ],
        ];
    }

    /**
     * Create dual quotations (Inclusión + Cross Selling) with sequential control numbers
     */
    public function createDualQuotations(array $inclusionData, array $crossSellingData, ?\Kanvas\Souk\Orders\Models\Order $order = null): array
    {
        $controlNumbers = $this->generateSequentialControlNumbers($order);

        // Update control numbers, organizations and convenios for both quotations
        $inclusionData['NroControl'] = $controlNumbers['inclusion'];
        $inclusionData['contrato'] = $this->getConvenioForQuotationType('inclusion');
        $crossSellingData['NroControl'] = $controlNumbers['cross_selling'];
        $crossSellingData['contrato'] = $this->getConvenioForQuotationType('cross_selling');

        // Set specific organizations for each quotation type
        if (isset($inclusionData['DatosAgencia'])) {
            $inclusionData['DatosAgencia']['OrganizacionRegistradora'] = $this->getOrganizationForQuotationType('inclusion');
        }
        if (isset($crossSellingData['DatosAgencia'])) {
            $crossSellingData['DatosAgencia']['OrganizacionRegistradora'] = $this->getOrganizationForQuotationType('cross_selling');
        }

        $results = [];

        try {
            // Create Inclusión quotation first with specific organization
            $results['inclusion'] = $this->createVoucher($inclusionData, true);

            // Query Inclusión voucher to get complete insurance information
            try {
                if (isset($results['inclusion']['IdVoucher'])) {
                    $inclusionQueryResponse = $this->queryVoucher([
                        'VoucherNumber' => $results['inclusion']['IdVoucher'],
                        'Organization' => $this->getOrganizationForQuotationType('inclusion')
                    ]);

                    // Filter and store essential query data
                    $results['inclusion_query'] = $this->filterVoucherQueryResponse($inclusionQueryResponse);
                }
            } catch (Exception $queryException) {
                $results['inclusion_query_error'] = $queryException->getMessage();
            }

            // Create Cross Selling quotation with specific organization
            $results['cross_selling'] = $this->createVoucher($crossSellingData, true);

            // Query Cross Selling voucher to get complete insurance information
            try {
                if (isset($results['cross_selling']['IdVoucher'])) {
                    $crossSellingQueryResponse = $this->queryVoucher([
                        'VoucherNumber' => $results['cross_selling']['IdVoucher'],
                        'Organization' => $this->getOrganizationForQuotationType('cross_selling')
                    ]);

                    // Filter and store essential query data
                    $results['cross_selling_query'] = $this->filterVoucherQueryResponse($crossSellingQueryResponse);
                }
            } catch (Exception $queryException) {
                $results['cross_selling_query_error'] = $queryException->getMessage();
            }

            $results['control_numbers'] = $controlNumbers;
            $results['organizations'] = [
                'inclusion' => $this->getOrganizationForQuotationType('inclusion'),
                'cross_selling' => $this->getOrganizationForQuotationType('cross_selling')
            ];
            $results['convenios'] = [
                'inclusion' => $this->getConvenioForQuotationType('inclusion'),
                'cross_selling' => $this->getConvenioForQuotationType('cross_selling')
            ];
        } catch (Exception $e) {
            throw new ValidationException('Failed to create dual quotations: ' . $e->getMessage());
        }

        return $results;
    }

    /**
     * Create a single quotation with specific organization type
     */
    public function createSingleQuotation(array $voucherData, string $quotationType, ?\Kanvas\Souk\Orders\Models\Order $order = null, bool $quoteOnly = false): array
    {
        // Generate control number for single quotation
        $sequentialNumber = $order ? $order->id : (int)(microtime(true) * 1000) % 10000;
        $baseControlNumber = 'UA-' . date('Ymd') . '-' . str_pad((string)$sequentialNumber, 4, '0', STR_PAD_LEFT);
        $controlNumber = $baseControlNumber . '-' . $this->getControlNumberSuffixForQuotationType($quotationType);

        // Set control number, organization and convenio
        $voucherData['NroControl'] = $controlNumber;
        $voucherData['contrato'] = $this->getConvenioForQuotationType($quotationType);

        if (isset($voucherData['DatosAgencia'])) {
            $voucherData['DatosAgencia']['OrganizacionRegistradora'] = $this->getOrganizationForQuotationType($quotationType);
        }

        try {
            // Define all valid countries of origin to try
            $validOrigins = [
                'TURQUIA',
                'TUVALU',
                'UCRANIA',
                'UGANDA',
                'URUGUAY',
                'USA',
                'UZBEKISTAN',
                'VANUATU',
                'VENEZUELA'
            ];

            // Use fixed destination: Centro america/Caribe (covers Dominican Republic)
            $destination = 'Centro america/Caribe';

            $quoteResult = null;
            $successfulOrigin = null;
            $triedOrigins = [];

            // Try each country of origin with fixed destination until we find one that returns products (not ErrorCode 01)
            foreach ($validOrigins as $origin) {
                try {

                    // Create a quote to get detailed product/plan information
                    $leadData = $this->convertVoucherDataToLeadData($voucherData, $quotationType, $origin);
                    $currentQuoteResult = $this->createOrUpdateLead($leadData, true);

                    $triedOrigins[] = [
                        'origin' => $origin,
                        'destination' => $destination,
                        'response' => $currentQuoteResult
                    ];

                    // If this origin returns products (no ErrorCode 01), use it
                    if (! $this->hasErrorCode01($currentQuoteResult)) {
                        $quoteResult = $currentQuoteResult;
                        $successfulOrigin = $origin;
                        break;
                    } else {
                    }
                } catch (Exception $originEx) {
                    $triedOrigins[] = [
                        'origin' => $origin,
                        'destination' => $destination,
                        'exception' => $originEx->getMessage()
                    ];
                    // Continue to next origin
                }
            }

            // If no origin worked, use the last tried result and log all attempts
            if ($quoteResult === null) {
                $quoteResult = $currentQuoteResult ?? ['ErrorCode' => '01', 'ErrorDescription' => 'No origins returned products'];
            }

            $result = [
                'quotation_type' => $quotationType,
                'control_number' => $controlNumber,
                'organization' => $this->getOrganizationForQuotationType($quotationType),
                'convenio' => $this->getConvenioForQuotationType($quotationType),
                'origin_used' => $successfulOrigin,
                'destination_used' => $destination,
                'tried_origins' => $triedOrigins,
                'quote_response' => $quoteResult,      // Detailed quote information
                'response' => $quoteResult             // Main response with all details for Excel
            ];

            // If the quote returned "no products" (ErrorCode '01'), retry once with an extended date range using the successful origin
            try {
                if ($this->hasErrorCode01($quoteResult) && $successfulOrigin) {

                    $retryLeadData = $this->convertVoucherDataToLeadData($voucherData, $quotationType, $successfulOrigin);
                    // Extend the date range to increase chance of finding products
                    $retryLeadData['FechaInicio'] = date('m/d/Y', strtotime('+1 day'));
                    $retryLeadData['FechaFin'] = date('m/d/Y', strtotime('+14 days')); // extended

                    $retryResult = $this->createOrUpdateLead($retryLeadData, true);

                    $result['retried'] = true;
                    $result['retry_response'] = $retryResult;

                    // If retry succeeded (no ErrorCode 01), prefer retry response as main response
                    if (! $this->hasErrorCode01($retryResult)) {
                        $result['quote_response'] = $retryResult;
                        $result['response'] = $retryResult;
                    } else {
                        $result['retry_failed'] = true;
                    }
                }
            } catch (Exception $retryEx) {
                $result['retried'] = true;
                $result['retry_exception'] = $retryEx->getMessage();
            }

            // Only create voucher if not quote-only mode
            if (! $quoteOnly) {
                $voucherResult = $this->createVoucher($voucherData, true);
                $result['voucher_response'] = $voucherResult;  // Voucher confirmation

                // Query voucher to get complete insurance information
                try {
                    if (isset($voucherResult['IdVoucher'])) {
                        $voucherQueryResponse = $this->queryVoucher([
                            'VoucherNumber' => $voucherResult['IdVoucher'],
                            'Organization' => $this->getOrganizationForQuotationType($quotationType)
                        ]);

                        // Filter and store essential query data
                        $result['voucher_query'] = $this->filterVoucherQueryResponse($voucherQueryResponse);
                    }
                } catch (Exception $queryException) {
                    $result['voucher_query_error'] = $queryException->getMessage();
                }
            }

            // Store in order metadata if provided
            if ($order) {
                $this->storeSingleQuotationInOrder($order, $quotationType, $controlNumber, $quoteResult);
            }

            $mode = $quoteOnly ? 'quote-only' : 'quote+voucher';

            return $result;
        } catch (Exception $e) {
            throw new ValidationException("Failed to create {$quotationType} quotation: " . $e->getMessage());
        }
    }

    /**
     * Convert voucher data to lead data format for quote generation
     */
    protected function convertVoucherDataToLeadData(array $voucherData, string $quotationType, string $countryOfOrigin = 'TURQUIA'): array
    {
        // Use the provided country of origin (defaults to TURQUIA from working request)

        // Convert voucher data structure to lead data structure using exact working request format
        return [
            'IdLead' => '',
            'OrganizacionEmisora' => $this->getOrganizationForQuotationType($quotationType),
            'Convenio' => $this->getConvenioForQuotationType($quotationType),
            'Folleto' => '', // Empty like working request
            'PaisOrigen' => $countryOfOrigin, // Use provided country of origin
            'Destino' => 'Centro america/Caribe', // Fixed destination from working request
            'TipoViaje' => 'Un viaje', // Correct value from working example
            'FechaInicio' => date('m/d/Y', strtotime('2025-12-15')), // December 15, 2025
            'FechaFin' => date('m/d/Y', strtotime('2025-12-22')), // December 22, 2025 (7 days)
            'CantidadPasajeros' => 4, // Match working request
            'Edad1' => 27, // Match working request ages
            'Edad2' => 38,
            'Edad3' => 28,
            'Edad4' => 65,
            'Edad5' => '', 'Edad6' => '', 'Edad7' => '', 'Edad8' => '', 'Edad9' => '', 'Edad10' => '',
            'ApellidoContacto' => '', // Empty like working request
            'NombreContacto' => '', // Empty like working request
            'TelefonoContacto' => '', // Empty like working request
            'EmailContacto' => '', // Empty like working request
            'Categoria' => '', // Empty like working request
            'Precompras' => '', // Empty like working request
            // Removed CantCotizaciones, PackFamiliar, NroDocumento, TipoDocumento - not in working request
        ];
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
    protected function storeSingleQuotationInOrder(\Kanvas\Souk\Orders\Models\Order $order, string $quotationType, string $controlNumber, array $result): void
    {
        $metadata = $order->metadata ?? [];

        if (! isset($metadata['universal_assistance'])) {
            $metadata['universal_assistance'] = [];
        }

        if (! isset($metadata['universal_assistance']['single_quotations'])) {
            $metadata['universal_assistance']['single_quotations'] = [];
        }

        $metadata['universal_assistance']['single_quotations'][$quotationType] = [
            'control_number' => $controlNumber,
            'organization' => $this->getOrganizationForQuotationType($quotationType),
            'convenio' => $this->getConvenioForQuotationType($quotationType),
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
                // Hardcoded WSDL URL for QA testing - Docker-compatible path
                $wsdlUrl = base_path('http___siebel.com_CustomUI_UA Lead Cotizador WS.WSDL');

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
                // Use the Voucher WSDL file for QA testing
                $wsdlUrl = base_path('http___siebel.com_CustomUI_UA Operaciones Voucher WS.WSDL');


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
                // Use the QueryVoucherPortal WSDL file for QA testing
                $wsdlUrl = base_path('http___siebel.com_CustomUI_UA QueryVoucherPortal WS.WSDL');


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
                            'ApellidoContacto' => $leadData['apellidoContacto'] ?? $leadData['ApellidoContacto'] ?? '',
                            'NombreContacto' => $leadData['nombreContacto'] ?? $leadData['NombreContacto'] ?? '',
                            'TelefonoContacto' => $leadData['telefonoContacto'] ?? $leadData['TelefonoContacto'] ?? '',
                            'EmailContacto' => $leadData['emailContacto'] ?? $leadData['EmailContacto'] ?? '',
                            'Categoria' => $leadData['categoria'] ?? $leadData['Categoria'] ?? '',
                            'Precompras' => $leadData['precompras'] ?? $leadData['Precompras'] ?? '',
                            'NroDocumento' => $leadData['nroDocumento'] ?? $leadData['NroDocumento'] ?? '',
                            'TipoDocumento' => $leadData['tipoDocumento'] ?? $leadData['TipoDocumento'] ?? '',
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


            // Return the raw SOAP response without any processing
            return (array) $response;
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
                            'NroControl' => $voucherData['nroControl'] ?? 'CTRL-PHP-' . substr((string)time(), -3),
                            'PostProcesoFlag' => $voucherData['postProcesoFlag'] ?? '',
                            'Vendedor' => $voucherData['vendedor'] ?? 'WSSIMLIMITEDO', // Use working QA username as default
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
                            'Contrato' => $voucherData['contrato'] ?? '1-DEY2E2H',
                            'LeadId' => $voucherData['leadId'] ?? $voucherData['idLead'] ?? '',
                            'EnvioVoucherMail' => $voucherData['envioVoucherMail'] ?? 'Y',
                            'ImprimeTarifa' => $voucherData['imprimeTarifa'] ?? 'N', // Campo "imprime tarifa" en "N"

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

            return (array) $response;
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

            $response = $client->__soapCall('BajaLead', [$parameters]);

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

            $response = $client->__soapCall('GeneracionPDF', [$parameters]);

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
                    'Tarifa' => $reportData['Tarifa'] ?? $reportData['tarifa'] ?? '',
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
                // Use the SendReport WSDL file for QA testing
                $wsdlUrl = base_path('http___siebel.com_CustomUI_UA SendReport WS.WSDL');

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
}
