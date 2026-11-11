<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Contracts\AppInterface;
use Baka\Search\SearchEngineResolver;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;
use Kanvas\Inventory\Recommendations\Enums\SearchFieldEnum;
use Override;
use Throwable;

/**
 * These indexes are shared with the live storefront, whose settings were tuned by hand in the
 * Algolia dashboard. Every write here is an append to what the dashboard already holds.
 */
class AlgoliaDiscoveryIndexService implements DiscoveryIndexAdapter
{
    private const array REQUIRED_FACETS = ['audience', 'in_stock'];

    /** Used only when the index has no explicit list, in the order a storefront should rank them. */
    private const array PROPOSED_SEARCHABLE = [
        'name',
        'variants.sku',
        'slug',
        'categories.name',
        'description',
        'short_description',
        'translations.name',
        'search_blurb',
    ];

    public function __construct(
        private readonly AppInterface $app,
        private ?SearchClient $client = null,
    ) {
    }

    #[Override]
    public function ensureFields(bool $dryRun, bool $fromDefault): array
    {
        $index = ProductDiscoveryResolver::collectionName($this->app);
        $settings = (array) $this->client()->getSettings($index);

        $searchable = self::settingList($settings, 'searchableAttributes');
        $facets = self::settingList($settings, 'attributesForFaceting');

        $update = [];
        $changes = [];
        $proposed = [];
        $refusal = null;

        if ($searchable === []) {
            $proposed = self::PROPOSED_SEARCHABLE;

            if ($fromDefault) {
                $update['searchableAttributes'] = $proposed;
                $changes[] = 'searchableAttributes: set to ' . implode(', ', $proposed);
            } else {
                $refusal = 'searchableAttributes is empty, which Algolia reads as "search every attribute". '
                    . 'Appending search_blurb would restrict storefront search to the blurb alone. '
                    . 'Review the proposed list and write it with --from-default.';
            }
        } elseif (! self::declares($searchable, SearchFieldEnum::BLURB->value)) {
            $update['searchableAttributes'] = [...$searchable, SearchFieldEnum::BLURB->value];
            $changes[] = 'searchableAttributes: append ' . SearchFieldEnum::BLURB->value . ' (last)';
        }

        $missingFacets = array_values(array_filter(
            self::REQUIRED_FACETS,
            static fn (string $facet): bool => ! self::declares($facets, $facet),
        ));

        if ($missingFacets !== []) {
            $additions = array_map(static fn (string $facet): string => 'filterOnly(' . $facet . ')', $missingFacets);
            $update['attributesForFaceting'] = [...$facets, ...$additions];
            $changes[] = 'attributesForFaceting: append ' . implode(', ', $additions);
        }

        $written = $update !== [] && ! $dryRun;

        if ($written) {
            $this->client()->setSettings($index, $update);
        }

        return [
            'index' => $index,
            'changes' => $changes,
            'written' => $written,
            'refusal' => $refusal,
            'proposed' => $proposed,
        ];
    }

    #[Override]
    public function coverage(int $version): array
    {
        $scope = 'apps_id = ' . $this->app->getId();
        $migratedFilter = 'discovery_schema_version >= ' . $version;

        $total = $this->count($scope);
        $migrated = $this->count($scope . ' AND ' . $migratedFilter);

        return [
            'total' => $total,
            'migrated' => $migrated,
            'stale_ids' => $migrated < $total ? $this->staleIds($scope, $migratedFilter, $version) : [],
        ];
    }

    /**
     * Whether Algolia accepts NOT on a numeric comparison is not something the docs settle, and
     * a rejected filter must not hide the stale list altogether, so it falls back to the older-version
     * filter, which only finds records that carry a lower version.
     *
     * @return list<int>
     */
    private function staleIds(string $scope, string $migratedFilter, int $version): array
    {
        try {
            return $this->ids($scope . ' AND NOT ' . $migratedFilter);
        } catch (Throwable) {
            return $this->ids($scope . ' AND discovery_schema_version < ' . $version);
        }
    }

    /**
     * @return list<int>
     */
    private function ids(string $filters): array
    {
        $response = $this->search($filters, self::STALE_ID_LIMIT, ['id']);

        return array_values(array_filter(array_map(
            static fn (array $hit): int => (int) ($hit['id'] ?? 0),
            (array) ($response['hits'] ?? []),
        )));
    }

    private function count(string $filters): int
    {
        return (int) ($this->search($filters, 1, [])['nbHits'] ?? 0);
    }

    /**
     * @param list<string> $retrieve
     *
     * @return array<string, mixed>
     */
    private function search(string $filters, int $hitsPerPage, array $retrieve): array
    {
        return (array) $this->client()->searchSingleIndex(
            ProductDiscoveryResolver::collectionName($this->app),
            [
                'query' => '',
                'filters' => $filters,
                'hitsPerPage' => $hitsPerPage,
                'attributesToRetrieve' => $retrieve,
                'attributesToHighlight' => [],
            ],
        );
    }

    /**
     * An attribute counts as present in any of Algolia's entry forms: `x`, `unordered(x)`,
     * `filterOnly(x)`, `searchable(x)`, or one of several in a comma-joined entry like "name,description".
     *
     * @param list<string> $entries
     */
    public static function declares(array $entries, string $attribute): bool
    {
        foreach ($entries as $entry) {
            $bare = (string) preg_replace('/^(?:unordered|filterOnly|searchable|afterDistinct)\((.+)\)$/', '$1', trim($entry));

            if (in_array($attribute, array_map('trim', explode(',', $bare)), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $settings an index's getSettings() response
     *
     * @return list<string>
     */
    public static function settingList(array $settings, string $key): array
    {
        return array_values(array_filter((array) ($settings[$key] ?? []), 'is_string'));
    }

    private function client(): SearchClient
    {
        return $this->client ??= SearchEngineResolver::algoliaClient($this->app);
    }
}
