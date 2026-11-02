<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\Contracts\ServesSearchDocuments;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQueryBuilder;
use Kanvas\Inventory\Recommendations\Discovery\HardConstraintVerifier;
use Kanvas\Inventory\Recommendations\Discovery\NumericAttributes;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoverySettings;
use Kanvas\Inventory\Recommendations\Services\ProductRecommendationPresenterService;
use Kanvas\Inventory\Recommendations\Services\SearchTermTokenizerService;
use Throwable;

/**
 * Answers from a third-party engine's documents without touching the database; the
 * SQL fallback reads products back, tenant-scoped. Either way every hard constraint
 * is re-checked on the rendered result before it leaves.
 */
class RecommendProductsAction
{
    private const int DEFAULT_LIMIT = 8;
    private const int MAX_LIMIT = 24;
    private const int DEFAULT_CACHE_TTL = 1800;
    private const int MAX_DOCUMENT_CACHE_TTL = 300;
    private const int MAX_CANDIDATE_POOL = 60;
    private const int MAX_CONSTRAINED_CANDIDATE_POOL = 100;
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

        return $this->executeIntent(
            ProductIntent::fromSentence($query, new IntentLexiconService($this->app)),
            $limit,
            $tasteVector,
        );
    }

    /**
     * For callers that already hold structured, validated input (the agent's
     * tool call, a storefront filter UI) laid over the parsed sentence.
     *
     * @param float[]|null $tasteVector
     *
     * @return array<int, array{product: array, variants: array}>
     */
    public function executeIntent(ProductIntent $intent, int $limit = self::DEFAULT_LIMIT, ?array $tasteVector = null): array
    {
        if (trim($intent->sentence) === '' && $intent->query === null) {
            return [];
        }

        $limit = min(max($limit, 1), self::MAX_LIMIT);

        // Wider than the page so diversify() has something to promote, and wider
        // still under hard constraints, which the recheck can thin out a lot.
        [$candidates, $fromDocuments] = $this->candidates($intent, $this->candidatePoolSize($limit, $intent), $tasteVector);

        if ($candidates === []) {
            return [];
        }

        $verifier = new HardConstraintVerifier($intent, NumericAttributes::fromApp($this->app));
        $presenter = new ProductRecommendationPresenterService($this->app, $this->company);

        $ranked = $fromDocuments
            ? $this->fromDocuments($candidates, $presenter, $verifier)
            : $this->fromDatabase($candidates, $presenter, $verifier);

        $ranked = array_values(array_filter($ranked, fn (array $result): bool => ! $this->isExcludedCategory($result)));

        return $this->diversify($this->demoteUnavailable($ranked), $limit);
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return array{0: list<array<string, mixed>>|list<int>, 1: bool} candidates, and whether they are documents
     */
    private function candidates(ProductIntent $intent, int $limit, ?array $tasteVector): array
    {
        $resolver = new ProductDiscoveryResolver($this->app, $this->company);

        // Switched off for this company or app: keyword fallback only. Unset keeps
        // today's behaviour, so the flag can never switch discovery off on deploy.
        $explicitlyOff = $this->discovery === null
            && ProductDiscoverySettings::for($this->app, $this->company)->discoveryExplicitlyOff();
        $service = $this->discovery ?? ($explicitlyOff ? $resolver->fallback() : $resolver->resolve());
        $query = new DiscoveryQueryBuilder($this->app, $this->company)->build($intent, $service);

        if ($tasteVector !== null && $tasteVector !== []) {
            return $this->runSearch(
                $service,
                $query,
                $intent,
                $limit,
                $tasteVector
            );
        }

        $key = $this->cacheKey($query, $limit, $explicitlyOff ? ProductDiscoveryResolver::ENGINE_SQL : $resolver->engine());
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['candidates'], $cached['documents'])) {
            return [$cached['candidates'], (bool) $cached['documents']];
        }

        [$candidates, $fromDocuments] = $this->runSearch(
            $service,
            $query,
            $intent,
            $limit,
            tasteVector: null
        );

        // Never cache empty: an engine blip would answer "nothing matched" for the whole TTL.
        if ($candidates !== []) {
            Cache::put($key, ['candidates' => $candidates, 'documents' => $fromDocuments], $this->cacheTtl($fromDocuments));
        }

        return [$candidates, $fromDocuments];
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return array{0: list<array<string, mixed>>|list<int>, 1: bool}
     */
    private function runSearch(
        ProductDiscoveryInterface $service,
        DiscoveryQuery $query,
        ProductIntent $intent,
        int $limit,
        ?array $tasteVector
    ): array {
        try {
            return $service instanceof ServesSearchDocuments
                ? [$service->searchDocuments($query, $limit, $tasteVector), true]
                : [$service->search($query, $limit, $tasteVector), false];
        } catch (Throwable $e) {
            Log::warning('Product discovery engine failed, falling back to SQL', [
                'app_id' => $this->app->getId(),
                'company_id' => $this->company->getId(),
                'message' => $e->getMessage(),
            ]);

            if ($this->discovery !== null) {
                return [[], false];
            }

            $fallback = new ProductDiscoveryResolver($this->app, $this->company)->fallback();

            return [
                $fallback->search(
                    new DiscoveryQueryBuilder($this->app, $this->company)->build($intent, $fallback),
                    $limit,
                ),
                false,
            ];
        }
    }

    /**
     * A third-party engine answered: render from its documents with no database read,
     * so search traffic never lands on the database. The engine query already filtered
     * on the tenant; the check here is the second guard, against a mis-scoped or shared
     * index.
     *
     * @param list<array<string, mixed>> $documents
     *
     * @return list<array{product: array, variants: array}>
     */
    private function fromDocuments(array $documents, ProductRecommendationPresenterService $presenter, HardConstraintVerifier $verifier): array
    {
        $crossCompany = ProductDiscoverySettings::crossCompanyVariants($this->app);
        $ranked = [];

        foreach ($documents as $document) {
            $ownTenant = (int) ($document['apps_id'] ?? 0) === (int) $this->app->getId()
                && ($crossCompany || (int) ($document['companies_id'] ?? 0) === (int) $this->company->getId());

            $result = $ownTenant ? $presenter->document($document) : null;
            $result = $result === null ? null : $verifier->filter($result, $this->documentAttributeLookup($document));

            if ($result !== null) {
                $ranked[] = $result;
            }
        }

        return $ranked;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return callable(int, string): mixed
     */
    private function documentAttributeLookup(array $document): callable
    {
        $variantAttributes = [];
        foreach ((array) ($document['variants'] ?? []) as $variant) {
            if (is_array($variant)) {
                $variantAttributes[(int) ($variant['id'] ?? 0)] = (array) ($variant['attributes'] ?? []);
            }
        }

        $productAttributes = (array) ($document['attributes'] ?? []);

        return static fn (int $variantId, string $attribute): mixed => $variantAttributes[$variantId][$attribute]
            ?? $productAttributes[$attribute]
            ?? null;
    }

    /**
     * The SQL fallback only nominates ids, so the products are read back, tenant-scoped,
     * with files and attributes loaded in batches.
     *
     * @param list<int> $ids
     *
     * @return list<array{product: array, variants: array}>
     */
    private function fromDatabase(array $ids, ProductRecommendationPresenterService $presenter, HardConstraintVerifier $verifier): array
    {
        $position = array_flip($ids);
        $products = $presenter->loadProducts($ids)
            ->sortBy(fn (Products $product): int => $position[$product->getId()] ?? PHP_INT_MAX)
            ->values();

        $ranked = [];

        foreach ($products as $product) {
            $result = $presenter->product($product);
            $result = $result === null ? null : $verifier->filter($result, $this->modelAttributeLookup($product));

            if ($result !== null) {
                $ranked[] = $result;
            }
        }

        return $ranked;
    }

    /**
     * Read only when the request has numeric filters. A variant's own attribute wins;
     * a product-level attribute applies to every variant.
     *
     * @return callable(int, string): mixed
     */
    private function modelAttributeLookup(Products $product): callable
    {
        return static function (int $variantId, string $attribute) use ($product): mixed {
            $variant = $product->variants->firstWhere('id', $variantId);

            return $variant?->getAttributeByName($attribute)?->value
                ?? $product->getAttributeByName($attribute)?->value;
        };
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

    private function candidatePoolSize(int $limit, ProductIntent $intent): int
    {
        return $intent->hasHardConstraints()
            ? min($limit * 6, self::MAX_CONSTRAINED_CANDIDATE_POOL)
            : min($limit * 3, self::MAX_CANDIDATE_POOL);
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
     * Everything that can change the candidates is in the key: the complete
     * normalized query (structured filters included — "SUV" under 100k miles and
     * under 50k miles must never share an entry), the engine and index, and the
     * settings that steer the search, so changing one doesn't serve stale
     * candidates for the rest of the TTL.
     */
    private function cacheKey(DiscoveryQuery $query, int $limit, string $engine): string
    {
        $settings = array_map(
            fn (string $key): mixed => $this->app->get($key),
            [
                ConfigurationEnum::RANGE_FILTERS->value,
                ConfigurationEnum::TYPESENSE_QUERY_BY->value,
                ConfigurationEnum::VECTOR_ALPHA->value,
                ConfigurationEnum::QUERY_LANGUAGES->value,
                ConfigurationEnum::NUMERIC_ATTRIBUTES->value,
                ConfigurationEnum::STOP_WORDS->value,
            ],
        );

        return 'product-discovery:' . $this->app->getId()
            . ':' . $this->company->getId()
            . ':' . $limit
            . ':' . md5((string) json_encode([
                $engine,
                ProductDiscoveryResolver::collectionName($this->app),
                $settings,
                $query->signature(),
            ]));
    }

    /**
     * Cached ids are re-read from the database, so they can live long. Cached documents carry
     * price and stock, so they are capped short: the cache must not add half an hour of
     * staleness on top of the index's own.
     */
    private function cacheTtl(bool $documents): int
    {
        $ttl = $this->app->get(ConfigurationEnum::CACHE_TTL->value);
        $ttl = is_numeric($ttl) ? (int) $ttl : self::DEFAULT_CACHE_TTL;

        return $documents ? min($ttl, self::MAX_DOCUMENT_CACHE_TTL) : $ttl;
    }
}
