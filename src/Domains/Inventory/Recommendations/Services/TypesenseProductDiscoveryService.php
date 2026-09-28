<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Facades\Cache;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Override;
use Throwable;
use Typesense\Client;

/**
 * One multi_search round trip. The sentence and the taste vector enter the same
 * vector space from different points, so they ride together rather than paying
 * the network twice.
 */
class TypesenseProductDiscoveryService implements ProductDiscoveryInterface
{
    /** Rank-fusion dampener; stops the top slot dominating everything below. */
    private const int RRF_K = 60;

    /** Long enough that the schema is not re-fetched per search, short enough that a reindex takes effect on its own. */
    private const int SCHEMA_CACHE_TTL = 600;

    private ?Client $client = null;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        ?Client $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * @param float[]|null $tasteVector
     *
     * @return list<int>
     */
    #[Override]
    public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
    {
        $searches = [$this->semanticSearch($intent, $limit)];

        if ($tasteVector !== null && $tasteVector !== []) {
            $searches[] = $this->tasteSearch($intent, $tasteVector, $limit);
        }

        // Failures bubble: the caller degrades to SQL rather than showing an empty catalog.
        $response = $this->client()->multiSearch->perform(
            ['searches' => $searches],
            ['exclude_fields' => 'embedding'],
        );

        return $this->fuse($response['results'] ?? [], $limit);
    }

    private function semanticSearch(ProductIntent $intent, int $limit): array
    {
        $queryBy = $this->queryByFields();

        $search = [
            'collection' => $this->collection(),
            'q' => $intent->sentence,
            'query_by' => $queryBy,
            'filter_by' => $this->filterBy($intent),
            'per_page' => $this->poolSize($limit),
        ];

        $weights = $this->queryByWeights();
        if ($weights !== null && substr_count($weights, ',') === substr_count($queryBy, ',')) {
            $search['query_by_weights'] = $weights;
        }

        // Naming `embedding` when the collection lacks it rejects the WHOLE search.
        if ($this->hasEmbeddingField($queryBy)) {
            $search['vector_query'] = sprintf('embedding:([], alpha: %s)', $this->vectorAlpha());
        }

        return $search;
    }

    /**
     * @param float[] $tasteVector
     */
    private function tasteSearch(ProductIntent $intent, array $tasteVector, int $limit): array
    {
        $vector = implode(',', array_map(static fn (float $value): string => (string) $value, $tasteVector));

        return [
            'collection' => $this->collection(),
            'q' => '*',
            'vector_query' => 'embedding:([' . $vector . '], k: ' . $this->poolSize($limit) . ')',
            'filter_by' => $this->filterBy($intent),
            'per_page' => $this->poolSize($limit),
        ];
    }

    /**
     * Narrows the pool only — the caller's DB read is the security boundary.
     */
    private function filterBy(ProductIntent $intent): string
    {
        $filters = ['apps_id:=' . $this->app->getId()];

        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $filters[] = 'companies_id:=' . $this->company->getId();
        }

        if ($intent->inStockOnly) {
            $filters[] = 'in_stock:=true';
        }

        // 0 means "price unknown" (the field is a typed float, no null). `price:<=X`
        // already admits it; the floor has to say so or it wipes out every unpriced product.
        if ($intent->minPrice !== null) {
            $filters[] = sprintf('(price:>=%s || price:=0)', $intent->minPrice);
        }

        if ($intent->maxPrice !== null) {
            $filters[] = sprintf('price:<=%s', $intent->maxPrice);
        }

        if ($intent->audience !== null && $this->collectionDeclares(SearchFieldEnum::AUDIENCE->value)) {
            $admitted = [$intent->audience, ...AudienceEnum::alwaysIncluded()];

            $filters[] = sprintf(
                'audience:[%s]',
                implode(', ', array_map(static fn (AudienceEnum $a): string => $a->value, $admitted)),
            );
        }

        return implode(' && ', $filters);
    }

    /**
     * Reciprocal-rank fusion. The two searches score on different scales (hybrid
     * text+vector vs raw cosine), so they are fused on rank position, not score.
     *
     * @return list<int>
     */
    private function fuse(array $results, int $limit): array
    {
        $scores = [];

        foreach ($results as $result) {
            foreach (array_values($result['hits'] ?? []) as $rank => $hit) {
                $id = (int) ($hit['document']['id'] ?? 0);

                if ($id === 0) {
                    continue;
                }

                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / (float) (self::RRF_K + $rank + 1);
            }
        }

        arsort($scores);

        return array_slice(array_map('intval', array_keys($scores)), 0, $limit);
    }

    /** Wider than the page: fusion and caller-side filtering both drop candidates. */
    private function poolSize(int $limit): int
    {
        return min($limit * 3, 100);
    }

    private function collection(): string
    {
        return ProductDiscoveryResolver::collectionName($this->app);
    }

    /**
     * Scout creates collections but never migrates them, so a field added to the
     * model schema is absent from every collection built before it — and Typesense
     * answers a filter on an undeclared field with NOTHING, not with everything.
     * Checking first costs the feature instead of every result.
     */
    private function collectionDeclares(string $field): bool
    {
        $collection = $this->collection();

        return Cache::remember(
            'product-discovery:fields:' . $collection . ':' . $field,
            self::SCHEMA_CACHE_TTL,
            function () use ($collection, $field): bool {
                try {
                    $schema = $this->client()->collections[$collection]->retrieve();
                } catch (Throwable) {
                    return false;
                }

                return in_array($field, array_column($schema['fields'] ?? [], 'name'), true);
            },
        );
    }

    private function queryByFields(): string
    {
        $fields = $this->app->get(ConfigurationEnum::TYPESENSE_QUERY_BY->value);

        return is_string($fields) && $fields !== ''
            ? $fields
            : (string) config('inventory-discovery.typesense_query_by', 'name,description');
    }

    private function queryByWeights(): ?string
    {
        $weights = config('inventory-discovery.typesense_query_by_weights');

        return is_string($weights) && $weights !== '' ? $weights : null;
    }

    private function hasEmbeddingField(string $queryBy): bool
    {
        return in_array('embedding', array_map('trim', explode(',', $queryBy)), true);
    }

    private function vectorAlpha(): float
    {
        $alpha = $this->app->get(ConfigurationEnum::VECTOR_ALPHA->value);

        return is_numeric($alpha) ? (float) $alpha : 0.75;
    }

    private function client(): Client
    {
        return $this->client ??= SearchEngineResolver::getTypesenseClient(
            (array) ($this->app->get('typesense_search_settings') ?? []),
        );
    }
}
