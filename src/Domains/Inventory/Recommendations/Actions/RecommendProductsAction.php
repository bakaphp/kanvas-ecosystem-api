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
use Kanvas\Inventory\Recommendations\Services\SearchTermTokenizerService;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Throwable;

/**
 * The engine only nominates ids; the DB read below is the tenant boundary.
 */
class RecommendProductsAction
{
    private const int DEFAULT_LIMIT = 8;
    private const int MAX_LIMIT = 24;
    private const int DEFAULT_CACHE_TTL = 1800;
    private const int MAX_CANDIDATE_POOL = 60;
    private const int DEFAULT_MAX_PER_GROUP = 2;
    private const int DEFAULT_GROUP_BY_TOKENS = 2;
    private const int DEFAULT_UNAVAILABLE_PENALTY = 3;

    private ?SearchTermTokenizerService $tokenizer = null;

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

        // Wider than the page so diversify() has something to promote.
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
        if ($tasteVector !== null && $tasteVector !== []) {
            return $this->runSearch($intent, $limit, $tasteVector);
        }

        $key = $this->cacheKey($intent, $limit);
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $ids = $this->runSearch($intent, $limit, null);

        // Never cache empty: an engine blip would answer "nothing matched" for the whole TTL.
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

        // Not fromCompany(): it widens to `companies_id > 0` under an AppKey binding.
        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $query->where('companies_id', $this->company->getId());
        }

        $ranked = $query->get()
            ->sortBy(fn (Products $product): int => $position[$product->getId()] ?? PHP_INT_MAX)
            ->map(fn (Products $product) => $presenter->product($product))
            ->filter()
            ->filter(fn (array $result): bool => ! $this->isExcludedCategory($result))
            ->filter(fn (array $result): bool => $this->withinBudget($result, $intent))
            ->values()
            ->all();

        return $this->diversify($this->demoteUnavailable($ranked), $limit);
    }

    /**
     * A rank penalty, NOT a partition: sorting every buyable product above every
     * unbuyable one lets a weak in-stock match leapfrog a near-perfect one. Sorts
     * are stable in PHP 8, so equal ranks keep the engine's order.
     *
     * @param array<int, array{product: array, variants: array}> $ranked
     *
     * @return array<int, array{product: array, variants: array}>
     */
    private function demoteUnavailable(array $ranked): array
    {
        $penalty = $this->unavailablePenalty();

        if ($penalty <= 0) {
            return $ranked;
        }

        $scored = [];

        foreach ($ranked as $position => $result) {
            $scored[] = [
                'rank' => $position + ($this->hasAvailableVariant($result) ? 0 : $penalty),
                'result' => $result,
            ];
        }

        usort($scored, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_column($scored, 'result');
    }

    private function hasAvailableVariant(array $result): bool
    {
        foreach ($result['variants'] as $variant) {
            if ($variant['channel']['is_available'] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Near-identical products embed alike, so one model in five colours takes the
     * whole page. Overflow is appended, not dropped, so a thin catalogue still fills.
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
            $key = $this->groupKey((string) ($result['product']['name'] ?? ''));
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

    /**
     * Grouping on the whole name gives every numbered variation its own budget, so
     * one product family takes the page. The leading tokens are what a shopper
     * would call the thing.
     */
    private function groupKey(string $name): string
    {
        $take = $this->groupByTokens();

        if ($take <= 0) {
            return IntentLexiconService::normalize($name);
        }

        $this->tokenizer ??= new SearchTermTokenizerService($this->app);

        return implode(' ', array_slice($this->tokenizer->tokenize($name), 0, $take));
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

    private function groupByTokens(): int
    {
        $tokens = $this->app->get(ConfigurationEnum::GROUP_BY_TOKENS->value)
            ?? config('inventory-discovery.group_by_tokens');

        return is_numeric($tokens) ? (int) $tokens : self::DEFAULT_GROUP_BY_TOKENS;
    }

    private function unavailablePenalty(): int
    {
        $penalty = $this->app->get(ConfigurationEnum::UNAVAILABLE_PENALTY->value)
            ?? config('inventory-discovery.unavailable_penalty');

        return is_numeric($penalty) ? (int) $penalty : self::DEFAULT_UNAVAILABLE_PENALTY;
    }

    /**
     * Some categories match well and are never what the shopper meant — gift wrap
     * on a gift query being the canonical case.
     */
    private function isExcludedCategory(array $result): bool
    {
        $excluded = $this->excludedCategories();

        foreach ($result['product']['categories'] ?? [] as $category) {
            if (in_array(IntentLexiconService::normalize((string) ($category['name'] ?? '')), $excluded, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function excludedCategories(): array
    {
        $configured = ConfigurationEnum::EXCLUDED_CATEGORIES->listFrom($this->app)
            ?: (array) config('inventory-discovery.excluded_categories', []);

        $names = [];

        foreach ($configured as $name) {
            if (is_string($name) && ($normalized = IntentLexiconService::normalize($name)) !== '') {
                $names[] = $normalized;
            }
        }

        return $names;
    }

    /**
     * Price lives on the variant channel, so the SQL path cannot filter it in-query.
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

        // Unpriced cannot be shown to break a budget, so it stays.
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
