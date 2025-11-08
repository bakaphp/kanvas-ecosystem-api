<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Souk\Orders\Models\Order;

class UniversalAssistanceService
{
    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
    }

    /**
     * Handle travel quote request
     */
    public function handleTravelQuote(array $travelData, ?People $contactPerson = null): array
    {
        // Normalizar country codes a nombres válidos
        if (isset($travelData['originCountryCode'])) {
            $travelData['originCountryCode'] = self::countryCodeToName($travelData['originCountryCode']);
        }
        if (isset($travelData['destinyCountryCode'])) {
            $travelData['destinyCountryCode'] = self::countryCodeToDestination($travelData['destinyCountryCode']);
        }

        $leadService = new LeadService($this->app, $this->order);
        try {
            $response = $leadService->createLead($travelData, $contactPerson);
            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'travel_quote_response' => $response,
                'travel_quote_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();
            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'travel_quote_error' => [
                    'error' => $e->getMessage(),
                    'data' => $travelData,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();
            throw $e;
        }
    }

    /**
     * Handle voucher creation
     */
    public function handleVoucherCreation(array $voucherData, People $applicant): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->createVoucher($voucherData, $applicant);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_creation_response' => $response,
                'voucher_creation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_creation_error' => [
                    'error' => $e->getMessage(),
                    'data' => $voucherData,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

            throw $e;
        }
    }

    /**
     * Handle voucher query
     */
    public function handleVoucherQuery(array $queryParams): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->queryVoucher($queryParams);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_query_response' => $response,
                'voucher_query_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'voucher_query_error' => [
                    'error' => $e->getMessage(),
                    'data' => $queryParams,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

            throw $e;
        }
    }

    /**
     * Handle PDF generation
     */
    public function handlePdfGeneration(string $voucherNumber): array
    {
        $voucherService = new VoucherService($this->app, $this->order);

        try {
            $response = $voucherService->generateVoucherPdf($voucherNumber);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'pdf_generation_response' => $response,
                'pdf_generation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'pdf_generation_error' => [
                    'error' => $e->getMessage(),
                    'voucher_number' => $voucherNumber,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

            throw $e;
        }
    }

    /**
     * Handle lead cancellation
     */
    public function handleLeadCancellation(string $leadId, string $reasonCode = 'Venta Online'): array
    {
        $leadService = new LeadService($this->app, $this->order);

        try {
            $response = $leadService->cancelLead($leadId, $reasonCode);

            // Store the response in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'lead_cancellation_response' => $response,
                'lead_cancellation_timestamp' => now()->toISOString(),
            ]);
            $this->order->saveOrFail();

            return $response;
        } catch (\Exception $e) {
            // Store the error in order metadata
            $this->order->metadata = array_merge(($this->order->metadata ?? []), [
                'lead_cancellation_error' => [
                    'error' => $e->getMessage(),
                    'lead_id' => $leadId,
                    'reason_code' => $reasonCode,
                    'timestamp' => now()->toISOString(),
                ],
            ]);
            $this->order->saveOrFail();

            throw $e;
        }
    }

    public static function countryCodeToName(string $countryCode): string
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
     * Convierte un código de país a región válida para destinos de Universal Assistance
     */
    public static function countryCodeToDestination(string $code): string
    {
        $destinationMap = [
            // America del norte
            'US' => 'America del norte',
            'CA' => 'America del norte',
            'MX' => 'America del norte',

            // Centro america/Caribe
            'DO' => 'Centro america/Caribe',
            'CR' => 'Centro america/Caribe',
            'GT' => 'Centro america/Caribe',
            'HN' => 'Centro america/Caribe',
            'NI' => 'Centro america/Caribe',
            'PA' => 'Centro america/Caribe',
            'SV' => 'Centro america/Caribe',
            'BZ' => 'Centro america/Caribe',
            'JM' => 'Centro america/Caribe',
            'CU' => 'Centro america/Caribe',
            'HT' => 'Centro america/Caribe',
            'PR' => 'Centro america/Caribe',

            // América del Sur (salvo Vzla)
            'ARG' => 'America del Sur (salvo Vzla)',
            'BR' => 'America del Sur (salvo Vzla)',
            'CL' => 'America del Sur (salvo Vzla)',
            'CO' => 'America del Sur (salvo Vzla)',
            'PE' => 'America del Sur (salvo Vzla)',
            'UY' => 'America del Sur (salvo Vzla)',
            'PY' => 'America del Sur (salvo Vzla)',
            'BO' => 'America del Sur (salvo Vzla)',
            'EC' => 'America del Sur (salvo Vzla)',
            'GY' => 'America del Sur (salvo Vzla)',
            'SR' => 'America del Sur (salvo Vzla)',
            'GF' => 'America del Sur (salvo Vzla)',

            // Europa
            'ES' => 'Europa',
            'FR' => 'Europa',
            'IT' => 'Europa',
            'DE' => 'Europa',
            'PT' => 'Europa',
            'GB' => 'Europa',
            'NL' => 'Europa',
            'BE' => 'Europa',
            'CH' => 'Europa',
            'AT' => 'Europa',

            // Asia
            'JP' => 'Asia',
            'CN' => 'Asia',
            'KR' => 'Asia',
            'IN' => 'Asia',
            'TH' => 'Asia',
            'SG' => 'Asia',

            // Africa
            'ZA' => 'Africa',
            'EG' => 'Africa',
            'MA' => 'Africa',
            'NG' => 'Africa',

            // Oceanía
            'AU' => 'Oceania',
            'NZ' => 'Oceania',
        ];

        $code = strtoupper($code);
        return $destinationMap[$code] ?? 'Europa'; // Default a Europa si no se encuentra
    }

    /**
     * Process order for Universal Assistance integration
     */
    public function processOrder(): array
    {
        // Get order metadata
        $orderMetadata = $this->order->metadata ?? [];

        if (! isset($orderMetadata['universalAssistanceData']) && ! isset($orderMetadata['universal_assistance'])) {
            throw new ValidationException('Universal Assistance data not found in order metadata');
        }

        // Prefer new format, fallback to old for backward compatibility
        $uaData = $orderMetadata['universalAssistanceData'] ?? $orderMetadata['universal_assistance'];
        $results = [];

        // Step 1: Create travel quote if needed
        if (isset($uaData['travel_data'])) {
            $contactPerson = $this->order->people;
            $results['quote'] = $this->handleTravelQuote($uaData['travel_data'], $contactPerson);
        }

        // Step 2: Create voucher after successful quote
        if (isset($uaData['voucher_data']) && ! empty($results['quote'])) {
            $applicant = $this->order->people;
            if (! $applicant) {
                throw new ValidationException('No applicant found for voucher creation');
            }

            $results['voucher'] = $this->handleVoucherCreation($uaData['voucher_data'], $applicant);
        }

        return $results;
    }
}
