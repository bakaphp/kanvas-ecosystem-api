<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use DomainException;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\PaymentInformation;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Mappers\ClienteMapper;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Actions\RecordInsuranceStatusAction;
use Kanvas\Insurance\Contracts\CanonicalQuoteProviderInterface;
use Kanvas\Insurance\Contracts\CatalogProviderInterface;
use Kanvas\Insurance\Contracts\CustomerDataProviderInterface;
use Kanvas\Insurance\Contracts\FreshQuoteProviderInterface;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\PaymentLinkProviderInterface;
use Kanvas\Insurance\Contracts\PaymentReportProviderInterface;
use Kanvas\Insurance\Contracts\PolicyEmissionProviderInterface;
use Kanvas\Insurance\Contracts\PolicySyncProviderInterface;
use Kanvas\Insurance\Contracts\ProductCatalogProviderInterface;
use Kanvas\Insurance\Contracts\QuoteUpdateProviderInterface;
use Kanvas\Insurance\Contracts\VehicleModelCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\CanonicalVehicle;
use Kanvas\Insurance\DataTransferObject\DocumentUploadResult;
use Kanvas\Insurance\DataTransferObject\ExternalVehicleModel;
use Kanvas\Insurance\DataTransferObject\InsuranceCustomer;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentOption;
use Kanvas\Insurance\DataTransferObject\InsurancePaymentReport;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\PaymentLinkResult;
use Kanvas\Insurance\DataTransferObject\PaymentReportResult;
use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\DataTransferObject\VehicleInsuranceProfile;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsurancePaymentTermEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Enums\VehicleInsuranceAttributeEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Services\CatalogCache;
use Kanvas\Insurance\Services\InsuranceStatus;
use Kanvas\Insurance\Services\InsuranceVehicleModelResolver;
use Kanvas\Insurance\Services\VehicleAttributeReader;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;

class UniversalSegurosProvider implements
    CanonicalQuoteProviderInterface,
    CatalogProviderInterface,
    CustomerDataProviderInterface,
    FreshQuoteProviderInterface,
    InspectionProviderInterface,
    InsuranceProviderInterface,
    PaymentLinkProviderInterface,
    PaymentReportProviderInterface,
    PolicyEmissionProviderInterface,
    PolicySyncProviderInterface,
    ProductCatalogProviderInterface,
    QuoteUpdateProviderInterface,
    VehicleModelCatalogProviderInterface
{
    public const NAME = 'universal_seguros';

    protected const DEFAULT_INSPECTION_TYPE = 'Cargar matrícula';

    protected const array DEFAULT_TERMINOS = [
        'seguroLey' => 'Auto Exceso',
        'asistenciaVehicular' => false,
        'autoSustituto' => 'No',
        'fraccionamientoPago' => 'A',
        'formaPago' => 't/c',
    ];

    protected const array PAYMENT_TERM_CODES = [
        'annual' => 'A',
        'semiannual' => 'S',
        'quarterly' => 'T',
        'monthly' => 'M',
    ];

    protected const CATALOG_TTL = [
        'vehicle_models' => 604800,
        'provinces' => 2592000,
        'municipalities' => 2592000,
        'sectors' => 2592000,
        'add_ons' => 86400,
        'rent_car_options' => 0,
    ];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UniversalSegurosService $service,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::UNIVERSAL_SEGUROS;
    }

    public function quote(InsuranceQuoteRequest $request): QuoteResult
    {
        $product = $this->productFor($request->product);

        return $this->toQuoteResult(
            $this->service->quote(
                QuoteRequest::make($product, $this->withDefaultTerminos($this->payloadWithVehicle($request), $request->paymentTerm))
            )
        );
    }

    public function freshQuote(string $quoteNumber, string $productCode, string $requestId): QuoteResult
    {
        $product = $this->productFor($productCode);

        $stored = (array) ($this->service->getQuote($quoteNumber)['data'] ?? []);
        $payload = array_intersect_key($stored, array_flip(['vehiculo', 'terminos', 'aditamentos', 'endosoCesion']));
        $payload['requestId'] = $requestId;

        return $this->toQuoteResult(
            $this->service->quote(QuoteRequest::make($product, $this->withDefaultTerminos($payload)))
        );
    }

    public function requiresVehicleModelId(): bool
    {
        return true;
    }

    public function quoteCanonical(
        CanonicalQuoteInput $input,
        string $planCode,
        ?string $vehicleModelId
    ): QuoteResult {
        $product = $this->productFor($planCode);

        return $this->quote(
            new InsuranceQuoteRequest(
                product: $planCode,
                payload: ['vehiculo' => $this->canonicalVehiculo($input, $product, $vehicleModelId)],
            )
        );
    }

    protected function canonicalVehiculo(
        CanonicalQuoteInput $input,
        ProductEnum $product,
        ?string $vehicleModelId
    ): array {
        return $this->vehiculoFor($input->vehicle, $input->profile, $product, $vehicleModelId);
    }

    protected function missingVehicleFields(
        CanonicalVehicle $vehicle,
        VehicleInsuranceProfile $profile,
        ?string $vehicleModelId
    ): array {
        return array_keys(array_filter([
            'vehicle model' => ! is_numeric($vehicleModelId),
            'year' => $vehicle->year === null,
            'fuel_type' => $profile->fuelType === null,
            'estimated_value' => $profile->estimatedValue === null,
        ]));
    }

    protected function vehiculoFor(
        CanonicalVehicle $vehicle,
        VehicleInsuranceProfile $profile,
        ProductEnum $product,
        ?string $vehicleModelId
    ): array {
        $missing = $this->missingVehicleFields($vehicle, $profile, $vehicleModelId);

        if ($missing !== []) {
            throw new ValidationException('Universal Seguros quote needs: ' . implode(', ', $missing));
        }

        $value = (int) round((float) $profile->estimatedValue);

        return array_filter([
            'idModelo' => (int) $vehicleModelId,
            'anio' => $vehicle->year,
            'esCeroKm' => $profile->isZeroKm ?? false,
            'combustible' => $profile->fuelType?->universalValue(),
            'cumpleRequisitos' => true,
            'esUsoParticularNoDeportivoNoPublico' => true,
            'valor' => $value,
            'sumaAsegurada' => $product === ProductEnum::POR_SI_CHOCAS ? $value : null,
            'inspeccion' => ['tipo' => self::DEFAULT_INSPECTION_TYPE],
        ], fn (mixed $field): bool => $field !== null);
    }

    public function updateQuote(string $quoteNumber, InsuranceQuoteRequest $request): QuoteResult
    {
        $product = $this->productFor($request->product);

        return $this->toQuoteResult(
            $this->service->updateQuote(
                $quoteNumber,
                QuoteRequest::make(
                    $product,
                    $this->withDefaultTerminos($this->payloadForUpdate($quoteNumber, $request, $product), $request->paymentTerm)
                )
            )
        );
    }

    public function requiredCustomerFields(?InsuranceCustomer $customer = null): array
    {
        return ClienteMapper::requiredFields($customer);
    }

    public function customerBlock(InsuranceCustomer $customer): array
    {
        return ClienteMapper::map($customer);
    }

    public function getQuote(string $quoteNumber): QuoteResult
    {
        return $this->toQuoteResult($this->service->getQuote($quoteNumber));
    }

    public function requiresInspection(Order $order): bool
    {
        $product = ProductEnum::tryFrom((string) $order->get(InsuranceCustomFieldEnum::PRODUCT_CODE->value));

        return $product?->requiresInspection() ?? false;
    }

    public function uploadDocuments(Order $order, array $documents): DocumentUploadResult
    {
        $quoteNumber = $this->quoteNumber($order);
        $results = [];

        foreach ($documents as $document) {
            $results[] = $this->service->uploadDocument(
                $quoteNumber,
                $this->toTransaction($document->type),
                DocumentOperationEnum::COTIZACION,
                $document->path
            );
        }

        if ($results !== [] && in_array(InsuranceStatus::of($order), [null, InsuranceStatusEnum::QUOTED], true)) {
            new RecordInsuranceStatusAction($order, InsuranceStatusEnum::DOCUMENTS_UPLOADED)->execute();
        }

        return new DocumentUploadResult(
            success: true,
            message: count($results) . ' document(s) uploaded',
            uploaded: count($results),
            raw: ['results' => $results],
        );
    }

    public function requestPaymentLink(Order $order, bool $byEmail = false): PaymentLinkResult
    {
        $quoteNumber = $this->quoteNumber($order);
        $url = null;

        if ($byEmail) {
            $response = $this->service->sendPaymentLinkEmail($quoteNumber);
        } else {
            $response = $this->service->getPaymentLink($quoteNumber);
            $url = ! empty($response['url']) ? (string) $response['url'] : null;

            if ($url !== null) {
                $order->set(InsuranceCustomFieldEnum::PAYMENT_URL->value, $url);
            }
        }

        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::AWAITING_PAYMENT)->execute();

        return new PaymentLinkResult(
            success: true,
            message: $byEmail ? 'Payment link sent by email' : 'Payment link generated',
            url: $url,
            sentByEmail: $byEmail,
            raw: $response,
        );
    }

    public function reportPayment(Order $order, InsurancePaymentReport $report): PaymentReportResult
    {
        $raw = $this->service->assignPaymentInformation(
            PaymentInformation::fromReport($report->forQuote($this->quoteNumber($order)))
        );

        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::PAID)->execute();

        return new PaymentReportResult(success: true, message: 'Payment reported', raw: $raw);
    }

    public function invoicePolicy(Order $order, InsurancePaymentReport $report): PaymentReportResult
    {
        $policyNumber = (string) $order->get(InsuranceCustomFieldEnum::POLICY_NUMBER->value);

        if ($policyNumber === '') {
            throw new ValidationException('Order has no policy number — emit it first.');
        }

        $raw = $this->service->invoicePolicy(
            PaymentInformation::fromReport($report->forPolicy($policyNumber))
        );

        return new PaymentReportResult(success: true, message: 'Policy invoiced', raw: $raw);
    }

    public function emit(Order $order): PolicyResult
    {
        $quoteNumber = $this->quoteNumber($order);

        $this->service->emit($quoteNumber);
        new RecordInsuranceStatusAction($order, InsuranceStatusEnum::EMITTED)->execute();

        return $this->readPolicy($order, $quoteNumber, 'Policy emitted');
    }

    public function syncPolicy(Order $order): PolicyResult
    {
        return $this->readPolicy($order, $this->quoteNumber($order), 'Policy synced');
    }

    public function getCatalog(string $catalog, array $params = []): array
    {
        return match ($catalog) {
            'vehicle_models' => $this->vehicleModels(
                (string) ($params['marca'] ?? ''),
                (string) ($params['modelo'] ?? '')
            ),
            'provinces' => $this->cached($catalog, [], fn (): array => $this->service->getProvincias()),
            'municipalities' => $this->cached(
                $catalog,
                ['provincia' => (string) ($params['provincia'] ?? '')],
                fn (): array => $this->service->getMunicipios((string) ($params['provincia'] ?? ''))
            ),
            'sectors' => $this->cached(
                $catalog,
                [
                    'provincia' => (string) ($params['provincia'] ?? ''),
                    'municipio' => (string) ($params['municipio'] ?? ''),
                ],
                fn (): array => $this->service->getSectores(
                    (string) ($params['provincia'] ?? ''),
                    (string) ($params['municipio'] ?? '')
                )
            ),
            'add_ons' => $this->cached($catalog, [], fn (): array => $this->service->getAditamentos()),
            'rent_car_options' => $this->service->getRentCarOptions(
                (string) ($params['codProd'] ?? ''),
                (string) ($params['codPlan'] ?? ''),
                (string) ($params['revPlan'] ?? ''),
                (string) ($params['codRamo'] ?? '')
            ),
            default => throw new ValidationException(
                'Unknown catalog: ' . $catalog . '. Available: ' . implode(', ', $this->availableCatalogs())
            ),
        };
    }

    public function vehicleModelEntries(): array
    {
        $catalog = $this->cached('vehicle_models', [], fn (): array => $this->service->getVehicleModels());
        $brands = is_array($catalog['data'] ?? null) ? $catalog['data'] : $catalog;
        $entries = [];

        foreach ($brands as $brand) {
            foreach (is_array($brand['modelos'] ?? null) ? $brand['modelos'] : [] as $model) {
                if (is_array($model) && isset($model['idModelo'], $model['modelo'], $brand['marca'])) {
                    $entries[] = new ExternalVehicleModel(
                        brand: (string) $brand['marca'],
                        model: (string) $model['modelo'],
                        externalId: (string) $model['idModelo'],
                    );
                }
            }
        }

        return $entries;
    }

    protected function payloadForUpdate(string $quoteNumber, InsuranceQuoteRequest $request, ProductEnum $product): array
    {
        $payload = $this->payloadWithVehicle($request);
        $vehicleProduct = $request->vehicle?->product;

        if (! isset($payload['vehiculo']) && $request->rebuildVehicle && $vehicleProduct !== null) {
            $payload['vehiculo'] = $this->vehiculoFromProduct($vehicleProduct, $product);
        }

        if (! isset($payload['vehiculo'])) {
            $current = $this->service->getQuote($quoteNumber);
            $stored = is_array($current['data'] ?? null) ? $current['data'] : [];

            if (! is_array($stored['vehiculo'] ?? null)) {
                throw new ValidationException('The vehicle of this quote could not be recovered, please quote again');
            }

            $payload += array_intersect_key($stored, array_flip(['vehiculo', 'aditamentos', 'endosoCesion']));
        }

        $payload['requestId'] ??= 'kanvas-' . hash_hmac(
            'sha256',
            $quoteNumber . '|' . json_encode($payload['cliente'] ?? []),
            (string) config('app.key')
        );

        return $payload;
    }

    protected function vehiculoFromProduct(Products $vehicleProduct, ProductEnum $product): array
    {
        $canonical = CanonicalVehicle::fromProduct($vehicleProduct);
        $modelId = new InsuranceVehicleModelResolver()->resolve($this->app, self::NAME, $canonical);

        $profile = VehicleInsuranceProfile::fromProduct($vehicleProduct);
        $missing = $this->missingVehicleFields($canonical, $profile, $modelId);

        if ($missing !== []) {
            throw new InsuranceValidationException(
                'We need more information about the vehicle: ' . str_replace('_', ' ', implode(', ', $missing))
            );
        }

        $vehiculo = $this->vehiculoFor($canonical, $profile, $product, $modelId);
        $vehiculo['inspeccion'] = array_merge($vehiculo['inspeccion'] ?? [], $this->inspeccionFrom($vehicleProduct));

        return $vehiculo;
    }

    protected function inspeccionFrom(Products $vehicleProduct): array
    {
        $read = fn (VehicleInsuranceAttributeEnum $attribute): ?string => VehicleAttributeReader::read($vehicleProduct, $attribute);
        $cylinders = $read(VehicleInsuranceAttributeEnum::CYLINDERS);
        $doors = $read(VehicleInsuranceAttributeEnum::DOORS);

        return array_filter([
            'placa' => $read(VehicleInsuranceAttributeEnum::PLATE),
            'chasis' => $read(VehicleInsuranceAttributeEnum::VIN),
            'color' => $read(VehicleInsuranceAttributeEnum::COLOR),
            'motor' => $read(VehicleInsuranceAttributeEnum::ENGINE),
            'cilindraje' => is_numeric($cylinders) ? (int) $cylinders : null,
            'puertas' => is_numeric($doors) ? (int) $doors : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    protected function productFor(string $code): ProductEnum
    {
        return ProductEnum::tryFrom($code)
            ?? throw new ValidationException('Unknown Universal Seguros product: ' . $code);
    }

    protected function withDefaultTerminos(array $payload, ?InsurancePaymentTermEnum $paymentTerm = null): array
    {
        $payload['terminos'] = array_merge(self::DEFAULT_TERMINOS, (array) ($payload['terminos'] ?? []));

        if ($paymentTerm !== null) {
            $payload['terminos']['fraccionamientoPago'] = self::PAYMENT_TERM_CODES[$paymentTerm->value];
        }

        return $payload;
    }

    protected function paymentOptions(array $response): array
    {
        $codes = array_flip(self::PAYMENT_TERM_CODES);
        $options = [];

        foreach ((array) ($response['data']['primas'] ?? []) as $entry) {
            $term = InsurancePaymentTermEnum::tryFrom($codes[$entry['fraccionamientoPago'] ?? ''] ?? '');
            $amount = $this->toFloat($entry['cobro'] ?? null);

            if ($term !== null && $amount !== null) {
                $options[] = new InsurancePaymentOption(
                    term: $term,
                    amount: $amount,
                    premium: $this->toFloat($entry['prima'] ?? null),
                    tax: $this->toFloat($entry['impuesto'] ?? null),
                );
            }
        }

        return $options;
    }

    protected function payloadWithVehicle(InsuranceQuoteRequest $request): array
    {
        $payload = $request->payload;
        $product = $request->vehicle?->product;

        if ($product === null || ! is_array($payload['vehiculo'] ?? null)) {
            return $payload;
        }

        $canonical = CanonicalVehicle::fromProduct($product);
        $profile = VehicleInsuranceProfile::fromProduct($product);
        $modelId = new InsuranceVehicleModelResolver()->resolve($this->app, self::NAME, $canonical);

        $defaults = array_filter([
            'idModelo' => is_numeric($modelId) ? (int) $modelId : null,
            'anio' => $canonical->year,
            'combustible' => $profile->fuelType?->universalValue(),
            'esCeroKm' => $profile->isZeroKm,
            'valor' => $profile->estimatedValue !== null ? (int) round($profile->estimatedValue) : null,
        ], fn (mixed $value): bool => $value !== null);

        $explicit = array_filter($payload['vehiculo'], fn (mixed $value): bool => $value !== null);
        $payload['vehiculo'] = $explicit + $defaults;

        return $payload;
    }

    public function availableCatalogs(): array
    {
        return ['vehicle_models', 'provinces', 'municipalities', 'sectors', 'add_ons', 'rent_car_options'];
    }

    public function products(): array
    {
        $granted = $this->grantedScopes();

        return array_map(
            fn (ProductEnum $product): InsuranceProduct => new InsuranceProduct(
                code: $product->value,
                name: $product->label(),
                requiresInspection: $product->requiresInspection(),
                isAvailable: in_array($product->emitScope(), $granted, true),
                metadata: ['tipo' => $product->defaultTipo()],
            ),
            ProductEnum::cases()
        );
    }

    protected function grantedScopes(): array
    {
        $scopes = trim((string) $this->company->get(ConfigurationEnum::SCOPES->value));

        return preg_split('/\s+/', $scopes !== '' ? $scopes : ConfigurationEnum::defaultScopes()) ?: [];
    }

    protected function vehicleModels(string $marca, string $modelo): array
    {
        $catalog = $this->cached('vehicle_models', [], fn (): array => $this->service->getVehicleModels());

        if ($marca === '' && $modelo === '') {
            return $catalog;
        }

        $brands = is_array($catalog['data'] ?? null) ? $catalog['data'] : $catalog;
        $matched = [];

        foreach ($brands as $brand) {
            if (! is_array($brand)) {
                continue;
            }

            if ($marca !== '' && ! $this->contains((string) ($brand['marca'] ?? ''), $marca)) {
                continue;
            }

            if ($modelo !== '') {
                $models = array_values(array_filter(
                    is_array($brand['modelos'] ?? null) ? $brand['modelos'] : [],
                    fn (mixed $m): bool => is_array($m) && $this->contains((string) ($m['modelo'] ?? ''), $modelo)
                ));

                if ($models === []) {
                    continue;
                }

                $brand['modelos'] = $models;
            }

            $matched[] = $brand;
        }

        return isset($catalog['data']) ? ['data' => $matched] + $catalog : $matched;
    }

    protected function contains(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    protected function cached(string $catalog, array $params, callable $fetch): array
    {
        return CatalogCache::remember(
            app: $this->app,
            company: $this->company,
            provider: self::NAME,
            catalog: $catalog,
            ttl: self::CATALOG_TTL[$catalog] ?? 0,
            callback: $fetch(...),
            params: $params,
        );
    }

    protected function readPolicy(Order $order, string $quoteNumber, string $message): PolicyResult
    {
        $policy = $this->service->getPolicy($quoteNumber);
        $policyNumber = (string) ($policy['numeroPoliza'] ?? $policy['numero'] ?? '');
        $status = InsuranceStatus::of($order) ?? InsuranceStatusEnum::QUOTED;

        if ($policyNumber !== '') {
            $order->set(InsuranceCustomFieldEnum::POLICY_NUMBER->value, $policyNumber);
            new RecordInsuranceStatusAction($order, InsuranceStatusEnum::POLICY_ACTIVE)->execute();
            $status = InsuranceStatusEnum::POLICY_ACTIVE;
        }

        return new PolicyResult(
            success: $policyNumber !== '',
            message: $policyNumber !== '' ? $message : 'Policy is not available yet',
            policyNumber: $policyNumber,
            status: $status,
            raw: $policy,
        );
    }

    protected function toQuoteResult(array $response): QuoteResult
    {
        $quoteNumber = (string) ($response['data']['numeroCotizacion'] ?? $response['numeroCotizacion'] ?? '');
        $terms = is_array($response['data']['terminos'] ?? null) ? $response['data']['terminos'] : [];

        $fixedPremium = $this->toFloat($terms['primaFija'] ?? null);

        return new QuoteResult(
            success: $quoteNumber !== '',
            message: $quoteNumber !== '' ? 'Quote created' : 'Universal Seguros did not return a quote number',
            quoteNumber: $quoteNumber,
            premium: ($fixedPremium !== null && $fixedPremium > 0) ? $fixedPremium : $this->toFloat($terms['prima'] ?? null),
            ratePerKm: $fixedPremium !== null ? $this->toFloat($terms['primaKm'] ?? null) : null,
            tax: $this->toFloat($terms['impuesto'] ?? null),
            total: $this->toFloat($terms['totalCobro'] ?? null),
            raw: $response,
            productCode: Str::trimToNull(
                is_string($response['data']['producto'] ?? null) ? $response['data']['producto'] : null
            ),
            paymentOptions: $this->paymentOptions($response),
        );
    }

    protected function quoteNumber(Order $order): string
    {
        $quoteNumber = (string) $order->get(InsuranceCustomFieldEnum::QUOTE_NUMBER->value);

        if ($quoteNumber === '') {
            throw new ValidationException('Order has no insurance quote number — quote it first.');
        }

        return $quoteNumber;
    }

    protected function toTransaction(InsuranceDocumentTypeEnum $type): DocumentTransactionEnum
    {
        return match ($type) {
            InsuranceDocumentTypeEnum::REGISTRATION => DocumentTransactionEnum::MATRICULA,
            InsuranceDocumentTypeEnum::INSPECTION_VIDEO => DocumentTransactionEnum::VIDEO_INSPECCION,
            InsuranceDocumentTypeEnum::IDENTIFICATION => DocumentTransactionEnum::PASAPORTE,
            InsuranceDocumentTypeEnum::OTHER => throw new DomainException(
                'Universal Seguros needs an explicit document type; OTHER is not mappable.'
            ),
        };
    }

    protected function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
