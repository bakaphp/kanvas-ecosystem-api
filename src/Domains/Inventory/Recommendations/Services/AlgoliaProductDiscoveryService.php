<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Algolia\AlgoliaSearch\Api\SearchClient;
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
use Override;
use Throwable;

/**
 * Keyword retrieval over the enriched records on a standard Algolia plan — no
 * NeuralSearch. The meaning comes from the agent's catalog-terms query and the
 * enrichment blurb, not from vectors.
 */
class AlgoliaProductDiscoveryService implements ProductDiscoveryInterface, ServesSearchDocuments
{
    private const int SETTINGS_CACHE_TTL = 600;

    private ?SearchClient $client = null;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        ?SearchClient $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * @param float[]|null $tasteVector ignored — no vector space on this plan
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
        $requests = array_map(
            fn (string $text): array => $this->request($text, $query, $limit),
            $query->texts(),
        );

        // Failures bubble: the caller degrades to SQL rather than showing an empty catalog.
        $response = $this->client()->search(['requests' => $requests]);

        return RankFusion::fuseDocuments(
            $response['results'] ?? [],
            static fn (array $hit): array => $hit,
            $limit,
        );
    }

    #[Override]
    public function supportsVectors(): bool
    {
        return false;
    }

    /**
     * Numeric filters need no declaration in Algolia, but a facet or boolean
     * filter on an attribute missing from `attributesForFaceting` fails the whole
     * request.
     */
    #[Override]
    public function canFilterOn(string $field): bool
    {
        return in_array($field, $this->facetableAttributes(), true);
    }

    /**
     * Identity terms are always required (`removeWordsIfNoResults: none`): its
     * other modes would drop "case" and then "phone" from "black phone case" on a
     * no-hit query. Only preferences are optional, and they must already be in
     * the query text for `optionalWords` to act on them.
     */
    private function request(string $text, DiscoveryQuery $query, int $limit): array
    {
        $languages = ProductDiscoverySettings::queryLanguages($this->app);

        return array_filter([
            'indexName' => ProductDiscoveryResolver::collectionName($this->app),
            'query' => $text,
            'optionalWords' => $query->optionalWords === [] ? null : $query->optionalWords,
            'removeWordsIfNoResults' => 'none',
            'filters' => $this->filters($query),
            'hitsPerPage' => RankFusion::poolSize($limit),
            'attributesToRetrieve' => ProductRecommendationPresenterService::DOCUMENT_FIELDS,
            'attributesToHighlight' => [],
            'removeStopWords' => $languages,
            'ignorePlurals' => $languages,
            'queryLanguages' => $languages,
            'analyticsTags' => ['product-discovery'],
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function filters(DiscoveryQuery $query): string
    {
        return implode(' AND ', array_map(
            fn (EqualsConstraint|InConstraint|RangeConstraint $constraint): string => match (true) {
                $constraint instanceof EqualsConstraint => $this->equals($constraint),
                $constraint instanceof InConstraint => '(' . implode(' OR ', array_map(
                    static fn (string $value): string => $constraint->field . ':' . self::quote($value),
                    $constraint->values,
                )) . ')',
                default => $this->range($constraint),
            },
            $query->constraints,
        ));
    }

    private function equals(EqualsConstraint $constraint): string
    {
        return match (true) {
            is_bool($constraint->value) => $constraint->field . ':' . ($constraint->value ? 'true' : 'false'),
            is_int($constraint->value) => $constraint->field . ' = ' . $constraint->value,
            default => $constraint->field . ':' . self::quote($constraint->value),
        };
    }

    private function range(RangeConstraint $constraint): string
    {
        $clause = implode(' AND ', array_filter([
            $constraint->min !== null ? sprintf('%s >= %s', $constraint->field, $constraint->min) : null,
            $constraint->max !== null ? sprintf('%s <= %s', $constraint->field, $constraint->max) : null,
        ]));

        return $constraint->alsoAllow === null
            ? $clause
            : sprintf('(%s OR %s = %s)', $clause, $constraint->field, $constraint->alsoAllow);
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace('"', '\"', $value) . '"';
    }

    /**
     * @return list<string>
     */
    private function facetableAttributes(): array
    {
        $index = ProductDiscoveryResolver::collectionName($this->app);

        return Cache::remember(
            'product-discovery:algolia-facets:' . $index,
            self::SETTINGS_CACHE_TTL,
            function () use ($index): array {
                try {
                    $settings = $this->client()->getSettings($index);
                } catch (Throwable) {
                    return [];
                }

                return array_map(
                    static fn (string $entry): string => (string) preg_replace('/^(?:filterOnly|searchable|afterDistinct)\((.+)\)$/', '$1', $entry),
                    (array) ($settings['attributesForFaceting'] ?? []),
                );
            },
        );
    }

    private function client(): SearchClient
    {
        return $this->client ??= SearchEngineResolver::algoliaClient($this->app);
    }
}
