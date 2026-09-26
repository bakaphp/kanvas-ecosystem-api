<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Humano\Providers;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Humano\DataTransferObject\QuoteRequest;
use Kanvas\Connectors\Humano\Enums\CurrencyEnum;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Services\HumanoService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Contracts\CatalogProviderInterface;
use Kanvas\Insurance\Contracts\InsuranceProviderInterface;
use Kanvas\Insurance\Contracts\ProductCatalogProviderInterface;
use Kanvas\Insurance\DataTransferObject\InsuranceProduct;
use Kanvas\Insurance\DataTransferObject\InsuranceQuoteRequest;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Services\CatalogCache;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * The only class that knows Humano's field names (`numeroCotizacion`,
 * `montoPrimaProrrata`, `cdPlan`). Nothing outside it should reference them.
 *
 * Deliberately narrower than the Universal adapter: their intermediary API issues
 * and charges only the travel line, so there is no auto emission or payment to
 * implement and this provider does not claim PolicyEmissionProviderInterface or
 * PaymentLinkProviderInterface. See CLAUDE.md.
 */
class HumanoProvider implements
    CatalogProviderInterface,
    InsuranceProviderInterface,
    ProductCatalogProviderInterface
{
    public const NAME = 'humano';

    /** Seconds; 0 means never cache. */
    protected const CATALOG_TTL = [
        'provinces' => 2592000,
        'municipalities' => 2592000,
        'sectors' => 2592000,
        'vehicle_brands' => 604800,
        'vehicle_models' => 604800,
        'vehicle_versions' => 604800,
    ];

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected HumanoService $service,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function integration(): IntegrationsEnum
    {
        return IntegrationsEnum::HUMANO;
    }

    public function quote(InsuranceQuoteRequest $request): QuoteResult
    {
        $plan = $this->plan($request->product);

        return $this->toQuoteResult(
            $this->service->quote(QuoteRequest::make($plan, $request->payload)),
            $this->currency($request->payload['codigo_moneda'] ?? null)
        );
    }

    public function getQuote(string $quoteNumber): QuoteResult
    {
        return $this->toQuoteResult($this->service->getQuote($quoteNumber), null, $quoteNumber);
    }

    public function getCatalog(string $catalog, array $params = []): array
    {
        return match ($catalog) {
            'provinces' => $this->cached($catalog, [], fn (): array => $this->service->getProvincias()),
            'municipalities' => $this->addressCatalog($catalog, $params),
            'sectors' => $this->addressCatalog($catalog, $params),
            'vehicle_brands', 'vehicle_models', 'vehicle_versions' => $this->vehicleCatalog($catalog, $params),
            default => throw new ValidationException(
                'Unknown catalog: ' . $catalog . '. Available: ' . implode(', ', $this->availableCatalogs())
            ),
        };
    }

    public function availableCatalogs(): array
    {
        return ['provinces', 'municipalities', 'sectors', 'vehicle_brands', 'vehicle_models', 'vehicle_versions'];
    }

    /**
     * Their API exposes no entitlement signal — unlike Universal, where the granted
     * emit scopes say which lines we may actually sell — so every plan is offered.
     * A plan the intermediary is not licensed for will surface as a quote error.
     */
    public function products(): array
    {
        return array_map(
            fn (PlanEnum $plan): InsuranceProduct => new InsuranceProduct(
                code: $plan->value,
                name: $plan->label(),
                description: $plan->description(),
                metadata: ['plan_code' => $plan->code()],
            ),
            PlanEnum::cases()
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    protected function addressCatalog(string $catalog, array $params): array
    {
        $province = (string) ($params['provincia'] ?? $params['cdProvincia'] ?? '');

        if ($province === '') {
            throw new ValidationException('Humano catalog ' . $catalog . ' requires a provincia');
        }

        if ($catalog === 'municipalities') {
            return $this->cached(
                $catalog,
                ['provincia' => $province],
                fn (): array => $this->service->getMunicipios($province)
            );
        }

        $municipality = (string) ($params['municipio'] ?? $params['cdMunicipio'] ?? '');

        if ($municipality === '') {
            throw new ValidationException('Humano catalog sectors requires a municipio');
        }

        return $this->cached(
            $catalog,
            ['provincia' => $province, 'municipio' => $municipality],
            fn (): array => $this->service->getSectores($province, $municipality)
        );
    }

    /**
     * Every vehicle catalog is scoped by plan — Humano filters the eligible brands,
     * models and versions per line, so a cache key without it would serve Mi Moto's
     * catalog to a car quote.
     *
     * @param array<string, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    protected function vehicleCatalog(string $catalog, array $params): array
    {
        $plan = $this->plan((string) ($params['plan'] ?? $params['product'] ?? ''));
        $planCode = $plan->code();

        if ($catalog === 'vehicle_brands') {
            return $this->cached(
                $catalog,
                ['plan' => $plan->value],
                fn (): array => $this->service->getMarcas($planCode)
            );
        }

        $brand = (string) ($params['marca'] ?? $params['cdMarca'] ?? '');

        if ($brand === '') {
            throw new ValidationException('Humano catalog ' . $catalog . ' requires a marca');
        }

        if ($catalog === 'vehicle_models') {
            return $this->cached(
                $catalog,
                ['plan' => $plan->value, 'marca' => $brand],
                fn (): array => $this->service->getModelos($planCode, $brand)
            );
        }

        $model = (string) ($params['modelo'] ?? $params['cdModelo'] ?? '');

        if ($model === '') {
            throw new ValidationException('Humano catalog vehicle_versions requires a modelo');
        }

        return $this->cached(
            $catalog,
            ['plan' => $plan->value, 'marca' => $brand, 'modelo' => $model],
            fn (): array => $this->service->getVersiones($planCode, $brand, $model)
        );
    }

    /**
     * Accepts our slug or their numeric code, so a caller holding a raw `cdPlan`
     * from a catalog response does not have to translate it back.
     */
    protected function plan(string $product): PlanEnum
    {
        return PlanEnum::tryFrom($product)
            ?? PlanEnum::fromCode($product)
            ?? throw new ValidationException(
                'Unknown Humano plan: ' . ($product === '' ? '(none given)' : $product)
                . '. Available: ' . implode(', ', array_column(PlanEnum::cases(), 'value'))
            );
    }

    protected function currency(mixed $value): ?CurrencyEnum
    {
        if ($value === null || $value === '') {
            return CurrencyEnum::DOP;
        }

        return CurrencyEnum::tryFrom((int) $value);
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

    /**
     * Prices come in two sets: Anual (the full-term price) and Prorrata (what the
     * chosen `codigoVigencia` actually costs). They are identical on an annual
     * quote — their own sample returns the same 50265.54 twice — which is exactly
     * what makes Anual the tempting wrong answer: it prices a monthly policy at
     * twelve times the amount and no annual test would catch it. Prorrata is right
     * for every vigencia, so that is what the graph gets; the annual figures and
     * `planesDePago` ride in `raw`.
     *
     * `montoComponenteProrrata` is mapped to tax because it is 16% of the premium
     * in their sample, matching the ISC on insurance premiums. Their doc does not
     * name it — confirm against a live quote before prod.
     *
     * @param array<string, mixed> $response
     */
    protected function toQuoteResult(
        array $response,
        ?CurrencyEnum $currency = null,
        string $fallbackQuoteNumber = ''
    ): QuoteResult {
        $quote = $this->firstQuote($response);
        $quoteNumber = (string) ($quote['numeroCotizacion'] ?? $fallbackQuoteNumber);

        return new QuoteResult(
            success: $quoteNumber !== '',
            message: $quoteNumber !== ''
                ? 'Quote created'
                : (string) ($response['codigoError'] ?? 'Humano did not return a quote number'),
            quoteNumber: $quoteNumber,
            premium: $this->toFloat($quote['montoPrimaProrrata'] ?? null),
            tax: $this->toFloat($quote['montoComponenteProrrata'] ?? null),
            total: $this->toFloat($quote['montoTotalProrrata'] ?? null),
            currency: $currency?->isoCode(),
            raw: $response,
        );
    }

    /**
     * `cotizaciones` is a list because a quote can cover several insured assets;
     * auto quotes send a single `numeroBien`, so the first line is the vehicle. The
     * whole response stays in `raw` so a caller that needs the rest still has it.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    protected function firstQuote(array $response): array
    {
        $quotes = $response['cotizaciones'] ?? null;

        if (is_array($quotes) && isset($quotes[0]) && is_array($quotes[0])) {
            return $quotes[0];
        }

        return $response;
    }

    protected function toFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
