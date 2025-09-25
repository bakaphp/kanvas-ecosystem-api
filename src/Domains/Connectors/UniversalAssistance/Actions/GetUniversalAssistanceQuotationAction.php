<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Actions;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Exception;
use Kanvas\Connectors\UniversalAssistance\Client;

class GetUniversalAssistanceQuotationAction
{
    /**
     * Get complete quotations with all available products for inclusion and cross selling
     * Handles EMISIVO vs RECEPTIVO logic with different conventions
     *
     * @param array $cartData (must include 'order', 'titular', and optionally 'dependents')
     * @param array $planVariant (name, id, duration, etc) - used as base for quotation
     */
    public static function run(AppInterface $app, array $cartData, array $planVariant): array
    {
        $results = [
            'inclusion' => [
                'type' => 'inclusion',
                'products' => [],
                'convenio_type' => null,
                'convenio_id' => null,
                'raw_responses' => []
            ],
            'cross_selling' => [
                'type' => 'cross_selling',
                'products' => [],
                'convenio_type' => null,
                'convenio_id' => null,
                'raw_responses' => []
            ]
        ];

        // Validate required data
        $titular = $cartData['titular'] ?? null;
        if (! $titular) {
            throw new Exception('Titular data is required');
        }

        if (! isset($cartData['order'])) {
            throw new Exception('Order data is required');
        }

        // Initialize client
        $client = new Client($app, $cartData['order']->company);

        // Extract origin and destination countries
        $originCountryCode = $titular['originCountryCode'] ?? 'AR';
        $destinationCountryCode = $titular['destinationCountryCode'] ?? $titular['destinyCountryCode'] ?? 'DO';

        // Determine if it's EMISIVO or RECEPTIVO
        $isReceptivo = ($destinationCountryCode === 'DO');
        $conventionType = $isReceptivo ? 'RECEPTIVO' : 'EMISIVO';

        // Combine planVariant with titular for quotation
        $titularWithPlan = array_merge($titular, ['plan' => $planVariant]);

        try {
            // INCLUSION QUOTATIONS - Can have multiple products according to convention
            if ($isReceptivo) {
                // RECEPTIVO: Use convention 1-EO7PJQQ for ASISTENCIA 10K REC products
                $results['inclusion'] = self::getReceptivoInclusionQuotes($app, $client, $cartData, $titularWithPlan, $originCountryCode, $destinationCountryCode);
            } else {
                // EMISIVO: Use convention 1-EO6M4QP for TELEASISTENCIA
                $results['inclusion'] = self::getEmisivoInclusionQuotes($app, $client, $cartData, $titularWithPlan, $originCountryCode, $destinationCountryCode);
            }
        } catch (Exception $e) {
            $results['inclusion']['error'] = $e->getMessage();
        }

        try {
            // CROSS SELLING QUOTATIONS
            if ($isReceptivo) {
                // RECEPTIVO: Use convention 1-EO7PJQL for ASISTENCIA 40K/80K REC products
                $results['cross_selling'] = self::getReceptivoCrossSellingQuotes($app, $client, $cartData, $titularWithPlan, $originCountryCode, $destinationCountryCode);
            } else {
                // EMISIVO: Use convention 1-EO6M4QU for ASISTENCIA 25K/40K/80K
                $results['cross_selling'] = self::getEmisivoCrossSellingQuotes($app, $client, $cartData, $titularWithPlan, $originCountryCode, $destinationCountryCode);
            }
        } catch (Exception $e) {
            $results['cross_selling']['error'] = $e->getMessage();
        }

        // Add context information
        $results['request_info'] = [
            'convention_type' => $conventionType,
            'origin_country' => $originCountryCode,
            'destination_country' => $destinationCountryCode,
            'is_receptivo' => $isReceptivo,
            'plan_variant_requested' => $planVariant['name'] ?? null,
            'duration' => $planVariant['duration'] ?? null,
            'titular_name' => ($titular['firstname'] ?? '') . ' ' . ($titular['lastname'] ?? ''),
            'dependents_count' => count($cartData['dependents'] ?? [])
        ];

        return $results;
    }

    /**
     * Build voucher data for inclusion quotation
     */
    protected static function buildVoucherData(
        AppInterface $app,
        array $personData,
        string $personType,
        string $originCountryCode,
        string $destinationCountryCode,
        Client $client
    ): array {
        // Convert destinationCountryCode to destination name
        $destination = self::getDestinationName($destinationCountryCode);

        // Calculate dates based on activation date and product duration
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = self::getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration);

        // Get convenio for inclusion
        $contract = $client->getConvenioForCountries($originCountryCode, $destinationCountryCode, 'inclusion');

        return [
            'NroControl' => '',
            'Vendedor' => $app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO',
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00',
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract,
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7',
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'],
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => self::getDocumentType($personData['idType'] ?? 'dni'),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => self::getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }

    /**
     * Build Cross Selling voucher data
     */
    protected static function buildCrossSellingVoucherData(
        AppInterface $app,
        array $personData,
        string $personType,
        string $originCountryCode,
        string $destinationCountryCode,
        Client $client
    ): array {
        // Convert destinationCountryCode to destination name
        $destination = self::getDestinationName($destinationCountryCode);

        // Calculate dates
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = self::getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration);

        // Get convenio for cross selling
        $contract = $client->getConvenioForCountries($originCountryCode, $destinationCountryCode, 'cross_selling');

        return [
            'NroControl' => '',
            'Vendedor' => $app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO',
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00',
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $contract,
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7',
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'],
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => self::getDocumentType($personData['idType'] ?? 'dni'),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => self::getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }

    /**
     * Helper methods (copied from InsuranceWorkflowService)
     */
    protected static function getDestinationName(string $countryCode): string
    {
        $countryToDestination = [
            'DO' => 'Territorio Nacional',
            'PA' => 'Centro america/Caribe', 'CR' => 'Centro america/Caribe', 'GT' => 'Centro america/Caribe',
            'US' => 'America del norte', 'CA' => 'America del norte', 'MX' => 'America del norte',
            'AR' => 'América del Sur (salvo Vzla)', 'BR' => 'América del Sur (salvo Vzla)', 'CL' => 'América del Sur (salvo Vzla)',
            'ES' => 'Europa', 'FR' => 'Europa', 'IT' => 'Europa', 'DE' => 'Europa',
            'CN' => 'Asia', 'JP' => 'Asia', 'IN' => 'Asia',
            'ZA' => 'Africa', 'EG' => 'Africa', 'MA' => 'Africa',
            'AU' => 'Oceanía', 'NZ' => 'Oceanía',
        ];

        return $countryToDestination[$countryCode] ?? 'Centro america/Caribe';
    }

    protected static function getDocumentType(string $idType): string
    {
        $types = [
            'passport' => 'Passport',
            'dni' => 'DNI',
            'cedula' => 'DNI',
            'license' => 'DNI',
        ];

        return $types[$idType] ?? 'DNI';
    }

    protected static function getCountryName(string $countryCode): string
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

        return $countries[$countryCode] ?? 'REPUBLICA DOMINICANA';
    }

    protected static function getProductDuration(array $personData): int
    {
        $duration = $personData['plan']['duration'] ??
                   $personData['plan']['attributes']['duration'] ??
                   $personData['duration'] ??
                   null;

        if ($duration !== null && $duration !== '') {
            $durationInt = (int) $duration;
            if ($durationInt > 0) {
                return $durationInt;
            }
        }

        return 7; // Default fallback
    }

    /**
     * Extract all available products from a quotation response
     */
    protected static function extractAllProductsFromQuote(array $quoteResponse, string $type): array
    {
        $products = [];

        // Extract main quotation data
        $quoteData = $quoteResponse['quote_response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    $quoteResponse['response']['UALeadCotizadorResp']['DatosLeadCotizadorOut'] ??
                    [];

        if (empty($quoteData)) {
            return [];
        }

        // Main product (always exists)
        $mainProduct = [
            'product_id' => $quoteData['IdProducto'] ?? null,
            'product_name' => $quoteData['NombreProducto'] ?? null,
            'family' => $quoteData['FamiliaProducto'] ?? null,
            'category' => $quoteData['Categoria'] ?? null,
            'brand' => $quoteData['Marca'] ?? null,
            'price_emission' => $quoteData['PrecioEmision'] ?? null,
            'price_net' => $quoteData['PrecioNeto'] ?? null,
            'price_gross' => $quoteData['PrecioBruto'] ?? null,
            'price_unit' => $quoteData['PrecioUnitario'] ?? null,
            'currency' => $quoteData['MonedaLista'] ?? null,
            'currency_local' => $quoteData['MonedaLocal'] ?? null,
            'exchange_rate' => $quoteData['TipoCambio'] ?? null,
            'geographic_scope' => $quoteData['AmbitoGeografico'] ?? null,
            'type' => $type,
            'source' => 'main_product'
        ];

        $products[] = $mainProduct;

        // Additional products in attributes/list
        $attributes = $quoteData['Atributo'] ?? $quoteData['attributes'] ?? $quoteData['productos'] ?? [];

        if (! empty($attributes) && is_array($attributes)) {
            foreach ($attributes as $index => $attribute) {
                if (! is_array($attribute)) {
                    continue;
                }

                $product = [
                    'product_id' => $attribute['IdProducto'] ?? $attribute['id'] ?? null,
                    'product_name' => $attribute['NombreProducto'] ??
                                    $attribute['NombreVisible'] ??
                                    $attribute['Nombre'] ??
                                    $attribute['product_name'] ??
                                    $attribute['name'] ?? null,
                    'family' => $attribute['FamiliaProducto'] ?? $attribute['family'] ?? null,
                    'category' => $attribute['Categoria'] ?? $attribute['category'] ?? null,
                    'brand' => $attribute['Marca'] ?? $attribute['brand'] ?? null,
                    'price_emission' => $attribute['PrecioEmision'] ?? $attribute['Precio'] ?? $attribute['price'] ?? null,
                    'price_net' => $attribute['PrecioNeto'] ?? $attribute['price_net'] ?? null,
                    'price_gross' => $attribute['PrecioBruto'] ?? $attribute['price_gross'] ?? null,
                    'price_unit' => $attribute['PrecioUnitario'] ?? $attribute['price_unit'] ?? null,
                    'currency' => $attribute['MonedaLista'] ?? $attribute['currency'] ?? $quoteData['MonedaLista'] ?? null,
                    'currency_local' => $attribute['MonedaLocal'] ?? $attribute['currency_local'] ?? $quoteData['MonedaLocal'] ?? null,
                    'exchange_rate' => $attribute['TipoCambio'] ?? $attribute['exchange_rate'] ?? $quoteData['TipoCambio'] ?? null,
                    'geographic_scope' => $attribute['AmbitoGeografico'] ?? $attribute['geographic_scope'] ?? null,
                    'type' => $type,
                    'source' => 'attribute',
                    'attribute_index' => $index
                ];

                // Solo agregar si tiene nombre de producto
                if ($product['product_name']) {
                    $products[] = $product;
                }
            }
        }

        return $products;
    }

    /**
     * Get quotations for EMISIVO inclusion products (convention 1-EO6M4QP)
     * Products: TELEASISTENCIA
     */
    protected static function getEmisivoInclusionQuotes(
        AppInterface $app,
        Client $client,
        array $cartData,
        array $titularWithPlan,
        string $originCountryCode,
        string $destinationCountryCode
    ): array {
        $result = [
            'type' => 'inclusion',
            'convenio_type' => 'EMISIVO',
            'convenio_id' => '1-EO6M4QP',
            'products' => [],
            'raw_responses' => []
        ];

        // Force specific convention for EMISIVO inclusion
        $voucherData = self::buildVoucherDataWithSpecificConvenio(
            $app,
            $titularWithPlan,
            'titular',
            $originCountryCode,
            $destinationCountryCode,
            '1-EO6M4QP'
        );

        $quote = $client->createSingleQuotationWithCountries(
            $voucherData,
            'inclusion',
            $originCountryCode,
            $destinationCountryCode,
            $cartData['order'],
            true
        );

        $result['raw_responses'][] = $quote;
        $result['products'] = self::extractAllProductsFromQuote($quote, 'inclusion_emisivo');

        return $result;
    }

    /**
     * Get quotations for RECEPTIVO inclusion products (convention 1-EO7PJQQ)
     * Products: ASISTENCIA 10K REC (different day ranges)
     */
    protected static function getReceptivoInclusionQuotes(
        AppInterface $app,
        Client $client, 
        array $cartData, 
        array $titularWithPlan, 
        string $originCountryCode, 
        string $destinationCountryCode
    ): array {
        $result = [
            'type' => 'inclusion',
            'convenio_type' => 'RECEPTIVO',
            'convenio_id' => '1-EO7PJQQ',
            'products' => [],
            'raw_responses' => []
        ];

        // Force specific convention for RECEPTIVO inclusion
        $voucherData = self::buildVoucherDataWithSpecificConvenio(
            $app,
            $titularWithPlan,
            'titular',
            $originCountryCode,
            $destinationCountryCode,
            '1-EO7PJQQ'
        );

        $quote = $client->createSingleQuotationWithCountries(
            $voucherData,
            'inclusion',
            $originCountryCode,
            $destinationCountryCode,
            $cartData['order'],
            true
        );

        $result['raw_responses'][] = $quote;
        $result['products'] = self::extractAllProductsFromQuote($quote, 'inclusion_receptivo');

        return $result;
    }

    /**
     * Get quotations for EMISIVO cross selling products (convention 1-EO6M4QU)
     * Products: ASISTENCIA 25K, 40K, 80K
     */
    protected static function getEmisivoCrossSellingQuotes(
        AppInterface $app,
        Client $client, 
        array $cartData, 
        array $titularWithPlan, 
        string $originCountryCode, 
        string $destinationCountryCode
    ): array {
        $result = [
            'type' => 'cross_selling',
            'convenio_type' => 'EMISIVO',
            'convenio_id' => '1-EO6M4QU',
            'products' => [],
            'raw_responses' => []
        ];

        // Force specific convention for EMISIVO cross selling
        $voucherData = self::buildCrossSellingVoucherDataWithSpecificConvenio(
            $app,
            $titularWithPlan,
            'titular',
            $originCountryCode,
            $destinationCountryCode,
            '1-EO6M4QU'
        );

        $quote = $client->createSingleQuotationWithCountries(
            $voucherData,
            'cross_selling',
            $originCountryCode,
            $destinationCountryCode,
            $cartData['order'],
            true
        );

        $result['raw_responses'][] = $quote;
        $result['products'] = self::extractAllProductsFromQuote($quote, 'cross_selling_emisivo');

        return $result;
    }

    /**
     * Get quotations for RECEPTIVO cross selling products (convention 1-EO7PJQL)
     * Products: ASISTENCIA 40K REC, 80K REC
     */
    protected static function getReceptivoCrossSellingQuotes(
        AppInterface $app,
        Client $client, 
        array $cartData, 
        array $titularWithPlan, 
        string $originCountryCode, 
        string $destinationCountryCode
    ): array {
        $result = [
            'type' => 'cross_selling',
            'convenio_type' => 'RECEPTIVO',
            'convenio_id' => '1-EO7PJQL',
            'products' => [],
            'raw_responses' => []
        ];

        // Force specific convention for RECEPTIVO cross selling
        $voucherData = self::buildCrossSellingVoucherDataWithSpecificConvenio(
            $app,
            $titularWithPlan,
            'titular',
            $originCountryCode,
            $destinationCountryCode,
            '1-EO7PJQL'
        );

        $quote = $client->createSingleQuotationWithCountries(
            $voucherData,
            'cross_selling',
            $originCountryCode,
            $destinationCountryCode,
            $cartData['order'],
            true
        );

        $result['raw_responses'][] = $quote;
        $result['products'] = self::extractAllProductsFromQuote($quote, 'cross_selling_receptivo');

        return $result;
    }

    /**
     * Build voucher data with specific convention
     */
    protected static function buildVoucherDataWithSpecificConvenio(
        AppInterface $app,
        array $personData,
        string $personType,
        string $originCountryCode,
        string $destinationCountryCode,
        string $specificConvenio
    ): array {
        $destination = self::getDestinationName($destinationCountryCode);
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = self::getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration);

        return [
            'NroControl' => '',
            'Vendedor' => $app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO',
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00',
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $specificConvenio, // Usar convenio específico
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7',
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'],
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => self::getDocumentType($personData['idType'] ?? 'dni'),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => self::getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }

    /**
     * Build cross selling voucher data with specific convention
     */
    protected static function buildCrossSellingVoucherDataWithSpecificConvenio(
        AppInterface $app,
        array $personData,
        string $personType,
        string $originCountryCode,
        string $destinationCountryCode,
        string $specificConvenio
    ): array {
        $destination = self::getDestinationName($destinationCountryCode);
        $activationDate = Carbon::parse($personData['activationDate'] ?? now());
        $duration = self::getProductDuration($personData);
        $expirationDate = clone $activationDate;
        $expirationDate->addDays($duration);

        return [
            'NroControl' => '',
            'Vendedor' => $app->get('UNIVERSAL_ASSISTANCE_USERNAME') ?: 'WSSIMLIMITEDO',
            'FechaEmision' => now()->format('m/d/Y'),
            'Destino' => $destination,
            'FechaVigencia' => $activationDate->format('m/d/Y'),
            'FechaFinal' => $expirationDate->format('m/d/Y'),
            'MonedaLista' => 'USD',
            'Precio' => '0.00',
            'NombreContactoVoucher' => '',
            'NroTelContactoVoucher' => '',
            'Canal' => 'Turismo',
            'Contrato' => $specificConvenio, // Usar convenio específico
            'LeadId' => '',
            'EnvioVoucherMail' => 'Y',
            'PostProcesoFlag' => 'N',
            'ImprimeTarifa' => 'N',

            'DatosAgencia' => [
                'OrganizacionRegistradora' => $app->get('UNIVERSAL_ASSISTANCE_ORGANIZATION') ?: '1-ENYNUF7',
            ],

            'DatosProducto' => [
                'NombreProducto' => $personData['plan']['name'],
            ],

            'DatosSolicitante' => [
                'NroPolizaSeguro' => '',
                'NombreSolicitante' => $personData['firstname'],
                'ApellidoSolicitante' => $personData['lastname'],
                'TipoDocumentoSolicitante' => self::getDocumentType($personData['idType'] ?? 'dni'),
                'NroDocumentoSolicitante' => $personData['idNumber'],
                'PaisResidenciaSolicitante' => self::getCountryName($originCountryCode),
                'FechaNacimientoSolicitante' => Carbon::parse($personData['dob'])->format('m/d/Y'),
                'CorreoElectronicoSolicitante' => $personData['email'],
            ],
        ];
    }
}