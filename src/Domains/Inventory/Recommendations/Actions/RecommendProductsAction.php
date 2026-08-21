<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Kanvas\Inventory\Recommendations\Services\ProductRecommendationPresenterService;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Throwable;

/**
 * Free-form sentence in, product payloads out.
 *
 * The search engine only nominates candidates; the database decides what the
 * caller actually sees. Every id comes back through a tenant-scoped read, so a
 * stale, mis-scoped or compromised index cannot surface another company's row.
 */
class RecommendProductsAction
{
    private const int DEFAULT_LIMIT = 8;
    private const int MAX_LIMIT = 24;
    private const int DEFAULT_CACHE_TTL = 1800;
    private const int MAX_CANDIDATE_POOL = 60;
    private const int DEFAULT_MAX_PER_GROUP = 2;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly ?ProductDiscoveryInterface $discovery = null,
    ) {
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return array<int, array{product: array, variants: array}>
     */
    public function execute(string $query, int $limit = self::DEFAULT_LIMIT, ?array $tasteVector = null): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $limit = min(max($limit, 1), self::MAX_LIMIT);
        $intent = ProductIntent::fromSentence($query, new IntentLexiconService($this->app));

        // Pull more candidates than the page holds: diversification can only
        // drop near-duplicates if there is something behind them to promote.
        $ids = $this->candidateIds($intent, $this->candidatePoolSize($limit), $tasteVector);

        return $ids === [] ? [] : $this->hydrate($ids, $intent, $limit);
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return list<int>
     */
    private function candidateIds(ProductIntent $intent, int $limit, ?array $tasteVector): array
    {
        // Personalized results are per-shopper, so they are never served from
        // the shared query cache.
        if ($tasteVector !== null && $tasteVector !== []) {
            return $this->runSearch($intent, $limit, $tasteVector);
        }

        $key = $this->cacheKey($intent, $limit);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $ids = $this->runSearch($intent, $limit, null);

        // Only a real result is cached. Caching an empty one would let a
        // momentary engine outage answer "nothing matched" for the whole TTL,
        // long after the engine came back.
        if ($ids !== []) {
            Cache::put($key, $ids, $this->cacheTtl());
        }

        return $ids;
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return list<int>
     */
    private function runSearch(ProductIntent $intent, int $limit, ?array $tasteVector): array
    {
        $resolver = new ProductDiscoveryResolver($this->app, $this->company);
        $service = $this->discovery ?? $resolver->resolve();

        try {
            return $service->search($intent, $limit, $tasteVector);
        } catch (Throwable $e) {
            // An unreachable or misconfigured engine degrades to keyword search
            // rather than showing the shopper an empty catalog. Logged, because
            // silence here is indistinguishable from "nothing matched".
            Log::warning('Product discovery engine failed, falling back to SQL', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
                'message' => $e->getMessage(),
            ]);

            if ($this->discovery !== null) {
                return [];
            }

            return $resolver->fallback()->search($intent, $limit, null);
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{product: array, variants: array}>
     */
    private function hydrate(array $ids, ProductIntent $intent, int $limit): array
    {
        $position = array_flip($ids);
        $presenter = new ProductRecommendationPresenterService($this->app, $this->company);

        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1)
            ->whereIn('id', $ids)
            ->with(['categories', 'variants.variantChannels.productVariantWarehouse']);

        // Not `fromCompany()`: that scope widens to `companies_id > 0` under an
        // AppKey binding, which would let ids from any company in the app
        // through. This is the boundary, so it stays pinned.
        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $query->where('companies_id', $this->company->getId());
        }

        $ranked = $query->get()
            ->sortBy(fn (Products $product): int => $position[$product->getId()] ?? PHP_INT_MAX)
            ->map(fn (Products $product) => $presenter->product($product))
            ->filter()
            ->filter(fn (array $result): bool => $this->withinBudget($result, $intent))
            ->values()
            ->all();

        return $this->diversify($ranked, $limit);
    }

    /**
     * Stops one product from taking the whole page.
     *
     * A dealer lists the same model once per colour, and near-identical products
     * embed to near-identical vectors — so an unfiltered ranking answers "a new
     * car for my girlfriend" with the same car three times. Anything dropped is
     * appended rather than discarded, so a thin catalogue still fills the page.
     *
     * @param array<int, array{product: array, variants: array}> $ranked
     *
     * @return array<int, array{product: array, variants: array}>
     */
    private function diversify(array $ranked, int $limit): array
    {
        $maxPerGroup = $this->maxResultsPerGroup();

        if ($maxPerGroup <= 0) {
            return array_slice($ranked, 0, $limit);
        }

        $kept = [];
        $overflow = [];
        $seen = [];

        foreach ($ranked as $result) {
            $key = IntentLexiconService::normalize((string) ($result['product']['name'] ?? ''));
            $count = $seen[$key] ?? 0;

            if ($key === '' || $count < $maxPerGroup) {
                $kept[] = $result;
                $seen[$key] = $count + 1;

                continue;
            }

            $overflow[] = $result;
        }

        return array_slice([...$kept, ...$overflow], 0, $limit);
    }

    private function candidatePoolSize(int $limit): int
    {
        return min($limit * 3, self::MAX_CANDIDATE_POOL);
    }

    private function maxResultsPerGroup(): int
    {
        $max = $this->app->get(ConfigurationEnum::MAX_RESULTS_PER_GROUP->value);

        return is_numeric($max) ? (int) $max : self::DEFAULT_MAX_PER_GROUP;
    }

    /**
     * The SQL path cannot filter on price in the query — price lives on the
     * variant channel — so the bound is enforced here for every backend.
     */
    private function withinBudget(array $result, ProductIntent $intent): bool
    {
        if (! $intent->hasPriceConstraint()) {
            return true;
        }

        foreach ($result['variants'] as $variant) {
            $price = $variant['channel']['price'] ?? null;

            if ($price === null || $price <= 0) {
                continue;
            }

            $aboveFloor = $intent->minPrice === null || $price >= $intent->minPrice;
            $belowCeiling = $intent->maxPrice === null || $price <= $intent->maxPrice;

            if ($aboveFloor && $belowCeiling) {
                return true;
            }
        }

        // An unpriced product cannot be shown to violate a budget, so it stays.
        return ! $this->hasAnyPricedVariant($result);
    }

    private function hasAnyPricedVariant(array $result): bool
    {
        foreach ($result['variants'] as $variant) {
            if (($variant['channel']['price'] ?? null) > 0) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(ProductIntent $intent, int $limit): string
    {
        return 'product-discovery:' . $this->app->getId()
            . ':' . $this->company->getId()
            . ':' . $limit
            . ':' . md5(IntentLexiconService::normalize($intent->sentence));
    }

    private function cacheTtl(): int
    {
        $ttl = $this->app->get(ConfigurationEnum::CACHE_TTL->value);

        return is_numeric($ttl) ? (int) $ttl : self::DEFAULT_CACHE_TTL;
    }
}
