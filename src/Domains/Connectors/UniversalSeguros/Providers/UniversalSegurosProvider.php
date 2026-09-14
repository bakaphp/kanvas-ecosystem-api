<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalSeguros\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use DomainException;
use Kanvas\Connectors\UniversalSeguros\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\CustomFieldEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentOperationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\DocumentTransactionEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Services\UniversalSegurosService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CatalogProviderInterface;
use Kanvas\Insurance\Contracts\InspectionProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\PaymentLinkProviderInterface;
use Kanvas\Insurance\Contracts\PolicyProviderInterface;
use Kanvas\Insurance\Contracts\ProductCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\DocumentUploadResult;
use Kanvas\Insurance\DataTransferObject\InsuranceDocument;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\PaymentLinkResult;
use Kanvas\Insurance\DataTransferObject\PolicyResult;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Enums\InsuranceDocumentTypeEnum;
use Kanvas\Insurance\Enums\InsuranceStatusEnum;
use Kanvas\Insurance\Services\CatalogCache;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;

class UniversalSegurosProvider implements
    CatalogProviderInterface,
    InspectionProviderInterface,
    InsuranceProviderInterface,
    PaymentLinkProviderInterface,
    PolicyProviderInterface,
    ProductCatalogProviderInterface
{
    public const NAME = 'universal_seguros';

    /** Seconds; 0 means never cache. */
    protected const CATALOG_TTL = [
        'vehicle_models' => 604800,
        'provinces' => 2592000,
        'municipalities' => 2592000,
        'sectors' => 2592000,
        'add_ons' => 86400,
        // Keyed off a plan revision that only exists after a quote — per customer.
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
        $product = ProductEnum::tryFrom($request->product)
            ?? throw new ValidationException('Unknown Universal Seguros product: ' . $request->product);

        return $this->toQuoteResult(
            $this->service->quote(QuoteRequest::make($product, $request->payload))
        );
    }

    public function getQuote(string $quoteNumber): QuoteResult
    {
        return $this->toQuoteResult($this->service->getQuote($quoteNumber));
    }

    public function requiresInspection(Order $order): bool
    {
        $product = ProductEnum::tryFrom((string) $order->get(CustomFieldEnum::PRODUCT->value));

        return $product?->requiresInspection() ?? false;
    }

    /**
     * @param list<InsuranceDocument> $documents
     */
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

        if ($results !== []) {
            $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::DOCUMENTS_UPLOADED->value);
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

        $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::AWAITING_PAYMENT->value);

        return new PaymentLinkResult(
            success: true,
            message: $byEmail ? 'Payment link sent by email' : 'Payment link generated',
            url: $url,
            sentByEmail: $byEmail,
            raw: $response,
        );
    }

    public function emit(Order $order): PolicyResult
    {
        $quoteNumber = $this->quoteNumber($order);

        $this->service->emit($quoteNumber);
        $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::EMITTED->value);

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

    /**
     * Emission is scoped per product and Universal licenses subsets, so a line
     * without its emit scope dies at emission — after the customer has paid.
     *
     * @return list<string>
     */
    protected function grantedScopes(): array
    {
        $scopes = trim((string) $this->company->get(ConfigurationEnum::SCOPES->value));

        return preg_split('/\s+/', $scopes !== '' ? $scopes : ConfigurationEnum::defaultScopes()) ?: [];
    }

    /**
     * `numeroPagina=-1` returns everything in one call, so it is cached whole and
     * narrowed here — filtering upstream would cost a round trip per keystroke.
     *
     * @return array<array-key, mixed>
     */
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

    /**
     * @param array<string, mixed> $params
     * @param callable(): array<array-key, mixed> $fetch
     *
     * @return array<array-key, mixed>
     */
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
        $status = InsuranceStatusEnum::tryFrom((string) $order->get(InsuranceCustomFieldEnum::STATUS->value))
            ?? InsuranceStatusEnum::QUOTED;

        if ($policyNumber !== '') {
            $order->set(InsuranceCustomFieldEnum::POLICY_NUMBER->value, $policyNumber);
            $order->set(InsuranceCustomFieldEnum::STATUS->value, InsuranceStatusEnum::POLICY_ACTIVE->value);
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

    /**
     * A-KM returns `prima: 0` alongside primaFija/primaKm, so presence of primaFija
     * — not a falsy prima — tells the two shapes apart. primaKm is a rate per
     * kilometer, never an addend: their sample is 1000 + 5.85 with totalCobro 1000.
     *
     * @param array<string, mixed> $response
     */
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
