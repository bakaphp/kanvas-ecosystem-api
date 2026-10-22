<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\Services\AlgoliaDiscoveryIndexService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlgoliaDiscoveryIndexServiceTest extends TestCase
{
    public function testAppendsTheBlurbLastAndTheFacetsWithoutTouchingExistingEntries(): void
    {
        $client = $this->clientWithSettings([
            'searchableAttributes' => ['name', 'unordered(description)'],
            'attributesForFaceting' => ['searchable(categories.name)', 'filterOnly(apps_id)'],
        ]);
        $client->shouldReceive('setSettings')
            ->once()
            ->with(Mockery::type('string'), [
                'searchableAttributes' => ['name', 'unordered(description)', 'search_blurb'],
                'attributesForFaceting' => [
                    'searchable(categories.name)',
                    'filterOnly(apps_id)',
                    'filterOnly(audience)',
                    'filterOnly(in_stock)',
                ],
            ]);

        $result = $this->service($client)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertTrue($result['written']);
        $this->assertNull($result['refusal']);
        $this->assertCount(2, $result['changes']);
    }

    public function testAnEmptySearchableListIsRefusedAndProposedInsteadOfAppended(): void
    {
        $client = $this->clientWithSettings([
            'attributesForFaceting' => ['filterOnly(audience)', 'filterOnly(in_stock)'],
        ]);
        $client->shouldNotReceive('setSettings');

        $result = $this->service($client)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertFalse($result['written']);
        $this->assertStringContainsString('search every attribute', (string) $result['refusal']);
        $this->assertSame('search_blurb', end($result['proposed']));
        $this->assertContains('name', $result['proposed']);
    }

    public function testFromDefaultWritesTheProposedListWhenTheIndexHasNone(): void
    {
        $client = $this->clientWithSettings([
            'searchableAttributes' => [],
            'attributesForFaceting' => ['filterOnly(audience)', 'filterOnly(in_stock)'],
        ]);
        $client->shouldReceive('setSettings')
            ->once()
            ->withArgs(fn (string $index, array $update): bool => array_keys($update) === ['searchableAttributes']
                && end($update['searchableAttributes']) === 'search_blurb'
                && $update['searchableAttributes'][0] === 'name');

        $result = $this->service($client)->ensureFields(dryRun: false, fromDefault: true);

        $this->assertTrue($result['written']);
        $this->assertNull($result['refusal']);
    }

    public function testFromDefaultNeverRewritesAnExistingList(): void
    {
        $client = $this->clientWithSettings([
            'searchableAttributes' => ['sku', 'search_blurb'],
            'attributesForFaceting' => ['filterOnly(audience)', 'filterOnly(in_stock)'],
        ]);
        $client->shouldNotReceive('setSettings');

        $result = $this->service($client)->ensureFields(dryRun: false, fromDefault: true);

        $this->assertFalse($result['written']);
        $this->assertSame([], $result['changes']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    public static function alreadyDeclared(): array
    {
        return [
            'plain' => [['name', 'search_blurb'], ['audience', 'in_stock']],
            'unordered' => [['name', 'unordered(search_blurb)'], ['unordered(audience)', 'in_stock']],
            'comma joined' => [['name,description,search_blurb'], ['audience, in_stock']],
            'wrapped facets' => [['name', 'search_blurb'], ['filterOnly(audience)', 'searchable(in_stock)']],
        ];
    }

    /**
     * @param list<string> $searchable
     * @param list<string> $facets
     */
    #[DataProvider('alreadyDeclared')]
    public function testAnAttributeInAnyEntryFormCountsAsPresent(array $searchable, array $facets): void
    {
        $client = $this->clientWithSettings([
            'searchableAttributes' => $searchable,
            'attributesForFaceting' => $facets,
        ]);
        $client->shouldNotReceive('setSettings');

        $result = $this->service($client)->ensureFields(dryRun: false, fromDefault: false);

        $this->assertSame([], $result['changes']);
        $this->assertFalse($result['written']);
    }

    public function testDryRunReportsTheDiffAndWritesNothing(): void
    {
        $client = $this->clientWithSettings(['searchableAttributes' => ['name']]);
        $client->shouldNotReceive('setSettings');

        $result = $this->service($client)->ensureFields(dryRun: true, fromDefault: false);

        $this->assertFalse($result['written']);
        $this->assertCount(2, $result['changes']);
    }

    public function testCoverageScopesBothCountsToTheApp(): void
    {
        $filters = [];
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('searchSingleIndex')
            ->andReturnUsing(function (string $index, array $params) use (&$filters): array {
                $filters[] = $params['filters'];

                return ['nbHits' => 4, 'hits' => []];
            });
        $appId = app(Apps::class)->getId();

        $coverage = $this->service($client)->coverage(1);

        $this->assertEqualsCanonicalizing(['apps_id = ' . $appId . ' AND discovery_schema_version >= 1', 'apps_id = ' . $appId], $filters);
        $this->assertSame(['total' => 4, 'migrated' => 4, 'stale_ids' => []], $coverage);
    }

    public function testStaleIdsAreListedAndCappedWhenSomeRecordsAreBehind(): void
    {
        $calls = [];
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('searchSingleIndex')
            ->andReturnUsing(function (string $index, array $params) use (&$calls): array {
                $calls[] = $params;

                if (str_contains($params['filters'], 'NOT')) {
                    return ['nbHits' => 3, 'hits' => [['id' => 7], ['id' => 9]]];
                }

                return ['nbHits' => str_contains($params['filters'], '>=') ? 1 : 3, 'hits' => []];
            });

        $coverage = $this->service($client)->coverage(1);

        $this->assertSame(['total' => 3, 'migrated' => 1, 'stale_ids' => [7, 9]], $coverage);
        $stale = end($calls);
        $this->assertSame(50, $stale['hitsPerPage']);
        $this->assertStringContainsString('apps_id = ' . app(Apps::class)->getId(), $stale['filters']);
    }

    public function testFallsBackToTheOlderVersionFilterWhenNotIsRejectedOnANumber(): void
    {
        $filters = [];
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('searchSingleIndex')
            ->andReturnUsing(function (string $index, array $params) use (&$filters): array {
                $filters[] = $params['filters'];

                if (str_contains($params['filters'], 'NOT')) {
                    throw new Exception('Invalid filter');
                }

                if (str_contains($params['filters'], '< 1')) {
                    return ['hits' => [['id' => 5]]];
                }

                return ['nbHits' => str_contains($params['filters'], '>=') ? 0 : 2, 'hits' => []];
            });

        $coverage = $this->service($client)->coverage(1);

        $this->assertSame([5], $coverage['stale_ids']);
        $this->assertSame(2, $coverage['total'] - $coverage['migrated']);
    }

    public function testTheIndexNameComesFromTheSharedResolverRule(): void
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('getSettings')
            ->once()
            ->with(ProductDiscoveryResolver::collectionName(app(Apps::class)))
            ->andReturn(['searchableAttributes' => ['search_blurb'], 'attributesForFaceting' => ['audience', 'in_stock']]);

        $result = $this->service($client)->ensureFields(dryRun: true, fromDefault: false);

        $this->assertSame(ProductDiscoveryResolver::collectionName(app(Apps::class)), $result['index']);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function clientWithSettings(array $settings): MockInterface
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('getSettings')->andReturn($settings);

        return $client;
    }

    private function service(SearchClient $client): AlgoliaDiscoveryIndexService
    {
        return new AlgoliaDiscoveryIndexService(app(Apps::class), $client);
    }
}
