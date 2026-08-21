<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Search\SearchEngineResolver;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Override;
use Typesense\Client;

/**
 * Candidate lookup against the tenant's Typesense collection.
 *
 * Every query the shopper waits on is ONE `multi_search` round trip. Two
 * searches can ride in it — the sentence, and the shopper's taste vector when
 * one exists — because they enter the same vector space from different points:
 * "what is near what they just typed" and "what is near what they liked
 * before". Sending them separately would pay the network round trip twice.
 */
class TypesenseProductDiscoveryService implements ProductDiscoveryInterface
{
    /**
     * Rank-fusion dampener. Standard value; large enough that the top slot does
     * not dominate everything below it.
     */
    private const int RRF_K = 60;

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

        // A Typesense failure is not swallowed here: the caller degrades to the
        // SQL path, which is more useful to a shopper than an empty result that
        // looks like "we sell nothing you want".
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

        // Only ask for the vector half when the collection actually declares the
        // auto-embed field — naming a field it does not have makes Typesense
        // reject the whole search rather than degrade.
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
     * Tenant scoping here narrows the candidate pool; it is not the security
     * boundary — the caller re-reads every id from the database under its own
     * scope regardless of what the index returns.
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

        // 0 is "price unknown", not "free" — the index has no null for a typed
        // float. Both bounds have to let it through or a floor filter wipes out
        // every unpriced product, which is what the caller deliberately keeps
        // and flags unavailable. `price:<=X` already admits 0; the floor needs
        // saying explicitly.
        if ($intent->minPrice !== null) {
            $filters[] = sprintf('(price:>=%s || price:=0)', $intent->minPrice);
        }

        if ($intent->maxPrice !== null) {
            $filters[] = sprintf('price:<=%s', $intent->maxPrice);
        }

        return implode(' && ', $filters);
    }

    /**
     * Reciprocal-rank fusion.
     *
     * The two searches score on different scales — a hybrid text+vector score
     * and a raw cosine distance — so adding them would let whichever produces
     * bigger numbers silently win. Fusing on rank position instead means a
     * product both searches like beats one that only a single search loved.
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

    /**
     * Pull a wider pool than we return: fusion and the caller's own filtering
     * both drop candidates, and a pool the size of the page starves them.
     */
    private function poolSize(int $limit): int
    {
        return min($limit * 3, 100);
    }

    private function collection(): string
    {
        return (string) config('scout.prefix') . (string) ($this->app->get('app_custom_product_index') ?? 'product_index');
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
