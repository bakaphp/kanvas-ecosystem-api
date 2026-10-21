<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Facades\Cache;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\Contracts\ServesSearchDocuments;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\EqualsConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\InConstraint;
use Kanvas\Inventory\Recommendations\Discovery\Constraints\RangeConstraint;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Recommendations\Discovery\RankFusion;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Override;
use Throwable;
use Typesense\Client;

/**
 * One multi_search round trip. The sentence, any alternative phrasings and the
 * taste vector enter from different points and ride together rather than paying
 * the network once each.
 */
class TypesenseProductDiscoveryService implements ProductDiscoveryInterface, ServesSearchDocuments
{
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
    /**
     * @param float[]|null $tasteVector
     *
     * @return list<int>
     */
    #[Override]
    public function search(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
    {
        return array_map(
            static fn (array $document): int => (int) $document['id'],
            $this->searchDocuments($query, $limit, $tasteVector),
        );
    }

    #[Override]
    public function searchDocuments(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
    {
        $filter = $this->filterBy($query);
        $searches = [];

        // The vector half embeds `q`. When the agent sent catalog terms, those drive
        // the keyword half while the shopper's own sentence drives the vector half.
        if ($this->supportsVectors() && $query->vectorText !== '' && $query->vectorText !== $query->text) {
            $searches[] = $this->keywordSearch($query->text, $filter, $limit);
            $searches[] = $this->semanticSearch($query->vectorText, $filter, $limit);
        } else {
            $searches[] = $this->semanticSearch($query->text, $filter, $limit);
        }

        foreach ($query->alternatives as $alternative) {
            $searches[] = $this->keywordSearch($alternative, $filter, $limit);
        }

        if ($tasteVector !== null && $tasteVector !== []) {
            $searches[] = $this->tasteSearch($tasteVector, $filter, $limit);
        }

        // Failures bubble: the caller degrades to SQL rather than showing an empty catalog.
        $response = $this->client()->multiSearch->perform(
            ['searches' => $searches],
            [
                'exclude_fields' => 'embedding',
                'include_fields' => implode(',', ProductRecommendationPresenterService::DOCUMENT_FIELDS),
            ],
        );

        return RankFusion::fuseDocuments(
            $response['results'] ?? [],
            static fn (array $hit): array => (array) ($hit['document'] ?? []),
            $limit,
        );
    }

    #[Override]
    public function supportsVectors(): bool
    {
        return $this->hasEmbeddingField($this->queryByFields());
    }

    #[Override]
    public function canFilterOn(string $field): bool
    {
        return $this->collectionDeclares($field);
    }

    private function semanticSearch(string $text, string $filter, int $limit): array
    {
        $queryBy = $this->queryByFields();

        $search = [
            'collection' => $this->collection(),
            'q' => $text,
            'query_by' => $queryBy,
            'filter_by' => $filter,
            'per_page' => RankFusion::poolSize($limit),
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

    private function keywordSearch(string $text, string $filter, int $limit): array
    {
        $fields = array_values(array_filter(
            array_map('trim', explode(',', $this->queryByFields())),
            static fn (string $field): bool => $field !== '' && $field !== 'embedding',
        ));

        return [
            'collection' => $this->collection(),
            'q' => $text,
            'query_by' => implode(',', $fields),
            'filter_by' => $filter,
            'per_page' => RankFusion::poolSize($limit),
        ];
    }

    /**
     * @param float[] $tasteVector
     */
    private function tasteSearch(array $tasteVector, string $filter, int $limit): array
    {
        $vector = implode(',', array_map(static fn (float $value): string => (string) $value, $tasteVector));

        return [
            'collection' => $this->collection(),
            'q' => '*',
            'vector_query' => 'embedding:([' . $vector . '], k: ' . RankFusion::poolSize($limit) . ')',
            'filter_by' => $filter,
            'per_page' => RankFusion::poolSize($limit),
        ];
    }

    private function filterBy(DiscoveryQuery $query): string
    {
        return implode(' && ', array_map(
            fn (EqualsConstraint|InConstraint|RangeConstraint $constraint): string => match (true) {
                $constraint instanceof EqualsConstraint => $constraint->field . ':=' . $this->literal($constraint->value),
                $constraint instanceof InConstraint => $constraint->field . ':[' . implode(', ', $constraint->values) . ']',
                default => $this->range($constraint),
            },
            $query->constraints,
        ));
    }

    private function range(RangeConstraint $constraint): string
    {
        $clause = implode(' && ', array_filter([
            $constraint->min !== null ? sprintf('%s:>=%s', $constraint->field, $constraint->min) : null,
            $constraint->max !== null ? sprintf('%s:<=%s', $constraint->field, $constraint->max) : null,
        ]));

        return $constraint->alsoAllow === null
            ? $clause
            : sprintf('(%s || %s:=%s)', $clause, $constraint->field, $constraint->alsoAllow);
    }

    private function literal(int|bool|string $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
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
        return $this->client ??= SearchEngineResolver::typesenseClient($this->app);
    }
}
