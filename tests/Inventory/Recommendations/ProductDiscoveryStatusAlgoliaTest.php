<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Exception;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryStatusService;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class ProductDiscoveryStatusAlgoliaTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected $connectionsToTransact = [null, 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(
            'products_search_engine',
            ConfigurationEnum::RANGE_FILTERS->value,
            ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value,
        );
        $app = app(Apps::class);
        $app->set('products_search_engine', 'algolia');
        $app->del(ConfigurationEnum::RANGE_FILTERS->value);
        $app->del(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value);
    }

    public function testAnAlgoliaAppGetsAlgoliaChecksNotTypesenseOnes(): void
    {
        $keys = array_column($this->report($this->client(['search_blurb'], ['filterOnly(audience)']))['checks'], 'key');

        $this->assertContains('algolia_credentials', $keys);
        $this->assertContains('algolia_searchable_blurb', $keys);
        $this->assertContains('range_filters', $keys);
        $this->assertNotContains('typesense_credentials', $keys);
        $this->assertNotContains('collection', $keys);
    }

    public function testPassesWhenTheBlurbIsSearchableAndAudienceIsFacetable(): void
    {
        $checks = $this->checks($this->client(['name', 'unordered(search_blurb)'], ['filterOnly(audience)', 'filterOnly(in_stock)']));

        $this->assertTrue($checks['algolia_credentials']['ok']);
        $this->assertTrue($checks['algolia_index']['ok']);
        $this->assertTrue($checks['algolia_searchable_blurb']['ok']);
        $this->assertTrue($checks['algolia_audience_facet']['ok']);
    }

    public function testFailsWithThePrepareCommandAsTheFix(): void
    {
        $checks = $this->checks($this->client(['name'], ['filterOnly(in_stock)']));

        foreach (['algolia_searchable_blurb', 'algolia_audience_facet'] as $key) {
            $this->assertFalse($checks[$key]['ok']);
            $this->assertStringContainsString('kanvas-inventory:prepare-discovery-index', (string) $checks[$key]['fix']);
        }
    }

    public function testAnEmptySearchableListSearchesEveryAttributeSoTheBlurbCounts(): void
    {
        $checks = $this->checks($this->client([], ['audience']));

        $this->assertTrue($checks['algolia_searchable_blurb']['ok']);
    }

    public function testRejectedCredentialsAndAnUnreadableIndexAreReported(): void
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('listIndices')->andThrow(new Exception('Invalid Application-ID or API key'));
        $client->shouldReceive('getSettings')->andThrow(new Exception('Index does not exist'));

        $checks = $this->checks($client);

        $this->assertFalse($checks['algolia_credentials']['ok']);
        $this->assertFalse($checks['algolia_index']['ok']);
        $this->assertStringContainsString('scout-product-index-process', (string) $checks['algolia_index']['fix']);
    }

    public function testRangeFiltersReportTheVerifiedVersionAgainstWhatTheCodeNeeds(): void
    {
        $client = $this->client(['search_blurb'], ['audience']);

        $before = $this->checks($client)['range_filters'];
        app(Apps::class)->set(ConfigurationEnum::RANGE_FILTERS->value, Products::DISCOVERY_SCHEMA_VERSION);
        $after = $this->checks($client)['range_filters'];

        $this->assertFalse($before['ok']);
        $this->assertStringContainsString('verify-discovery-index', (string) $before['fix']);
        $this->assertTrue($after['ok']);
    }

    public function testReportsEachFlagWithItsValueAndWhereItCameFrom(): void
    {
        app(Apps::class)->set(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value, true);

        $flags = $this->report($this->client(['search_blurb'], ['audience']))['flags'];

        $this->assertSame(['value' => true, 'source' => 'app'], $flags['product_enrichment_enabled']);
        $this->assertSame(['value' => null, 'source' => 'default'], $flags['product_discovery_enabled']);
    }

    /**
     * @param list<string> $searchable
     * @param list<string> $facets
     */
    private function client(array $searchable, array $facets): SearchClient
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('listIndices')->andReturn(['items' => []]);
        $client->shouldReceive('getSettings')->andReturn([
            'searchableAttributes' => $searchable,
            'attributesForFaceting' => $facets,
        ]);

        return $client;
    }

    /**
     * @return array{ready: bool, checks: list<array{key: string, ok: bool, detail: string, fix: ?string}>, flags: array<string, array{value: ?bool, source: string}>}
     */
    private function report(SearchClient $client): array
    {
        return new ProductDiscoveryStatusService(app(Apps::class), auth()->user()->getCurrentCompany(), $client)->report();
    }

    /**
     * @return array<string, array{key: string, ok: bool, detail: string, fix: ?string}>
     */
    private function checks(SearchClient $client): array
    {
        $checks = $this->report($client)['checks'];

        return array_combine(array_column($checks, 'key'), $checks);
    }
}
