<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Search\SearchEngineResolver;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;
use Override;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Scout creates a collection from the model's schema once and never revisits it, so a field added
 * later only reaches an existing collection through a PATCH like this one. Reindexing alone pushes
 * values Typesense ignores.
 */
class TypesenseDiscoveryIndexService implements DiscoveryIndexAdapter
{
    private const array DISCOVERY_FIELDS = [
        'price_min',
        'price_max',
        'discovery_schema_version',
        'num_.*',
        'audience',
    ];

    public function __construct(
        private readonly AppInterface $app,
        private ?Client $client = null,
    ) {
    }

    #[Override]
    public function ensureFields(bool $dryRun, bool $fromDefault): array
    {
        $index = ProductDiscoveryResolver::collectionName($this->app);

        try {
            $collection = $this->client()->collections[$index]->retrieve();
        } catch (ObjectNotFound) {
            return [
                'index' => $index,
                'changes' => ["collection '{$index}' does not exist yet; the first reindex creates it with the full schema"],
                'written' => false,
                'refusal' => null,
                'proposed' => [],
            ];
        }

        $declared = array_column((array) ($collection['fields'] ?? []), 'name');
        $missing = array_values(array_filter(
            $this->schemaFields(),
            static fn (array $field): bool => ! in_array($field['name'], $declared, true),
        ));

        $written = $missing !== [] && ! $dryRun;

        if ($written) {
            $this->client()->collections[$index]->update(['fields' => $missing]);
        }

        return [
            'index' => $index,
            'changes' => array_map(
                static fn (array $field): string => 'add field ' . $field['name'] . ' (' . $field['type'] . ')',
                $missing,
            ),
            'written' => $written,
            'refusal' => null,
            'proposed' => [],
        ];
    }

    #[Override]
    public function coverage(int $version): array
    {
        $scope = 'apps_id:=' . $this->app->getId();
        $migrated = $this->search($scope . ' && discovery_schema_version:>=' . $version, 1);
        $total = $this->search($scope, 1);

        $stale = [];

        if ($migrated['found'] < $total['found']) {
            $stale = $this->search($scope . ' && discovery_schema_version:<' . $version, self::STALE_ID_LIMIT)['ids'];
        }

        return [
            'total' => $total['found'],
            'migrated' => $migrated['found'],
            'stale_ids' => $stale,
        ];
    }

    /**
     * Read from the model so the live collection and a freshly created one cannot disagree on a type.
     *
     * @return list<array<string, mixed>>
     */
    private function schemaFields(): array
    {
        $product = new Products();
        // The schema reads the app's embedding settings, and a blank model has no app to read them from.
        $product->setRelation('app', $this->app);

        return array_values(array_filter(
            $product->typesenseCollectionSchema()['fields'],
            static fn (array $field): bool => in_array($field['name'], self::DISCOVERY_FIELDS, true),
        ));
    }

    /**
     * @return array{found: int, ids: list<int>}
     */
    private function search(string $filterBy, int $perPage): array
    {
        $response = $this->client()->collections[ProductDiscoveryResolver::collectionName($this->app)]
            ->documents
            ->search([
                'q' => '*',
                'filter_by' => $filterBy,
                'per_page' => $perPage,
                'include_fields' => 'id',
            ]);

        return [
            'found' => (int) ($response['found'] ?? 0),
            'ids' => array_values(array_filter(array_map(
                static fn (array $hit): int => (int) ($hit['document']['id'] ?? 0),
                (array) ($response['hits'] ?? []),
            ))),
        ];
    }

    private function client(): Client
    {
        return $this->client ??= SearchEngineResolver::typesenseClient($this->app);
    }
}
