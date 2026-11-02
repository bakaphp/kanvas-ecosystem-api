<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQueryBuilder;
use Kanvas\Inventory\Recommendations\Enums\AudienceEnum;
use Kanvas\Inventory\Recommendations\Services\AlgoliaProductDiscoveryService;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\ProductRecommendationPresenterService;
use Mockery;
use Tests\TestCase;

class AlgoliaProductDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private array $captured = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Facet settings are cached per index; a sibling case's answer would leak in.
        Cache::flush();
    }

    public function testBuildsQueryFromIdentityTermsPlusPreferencesAndMarksOnlyPreferencesOptional(): void
    {
        $service = $this->service([[10]]);

        $intent = $this->intent('a black phone case please')->withOverrides(query: 'phone case', preferences: ['black']);
        $service->search($this->builder()->build($intent, $service), 5);

        $request = $this->captured[0]['requests'][0];
        $this->assertSame('phone case black', $request['query'], 'optionalWords only acts on words already in the query.');
        $this->assertSame(['black'], $request['optionalWords']);
        $this->assertSame('none', $request['removeWordsIfNoResults'], 'lastWords would drop "case" then "phone".');
        $this->assertArrayNotHasKey('restrictSearchableAttributes', $request, 'Restricting would drop SKU/name matching and cannot reorder.');
    }

    public function testScopesToTheTenantAndTranslatesPriceWithTheUnknownPricePolicy(): void
    {
        $service = $this->service([[10]]);
        $app = app(Apps::class);

        $intent = $this->intent('reloj')->withOverrides(minPrice: 100.0, maxPrice: 500.0);
        $service->search($this->builder()->build($intent, $service), 5);

        $filters = $this->captured[0]['requests'][0]['filters'];
        $this->assertStringContainsString('apps_id = ' . $app->getId(), $filters);
        $this->assertStringContainsString('(price >= 100 OR price = 0)', $filters);
        $this->assertStringContainsString('price <= 500', $filters);
    }

    public function testFiltersOnAudienceOnlyWhenTheIndexDeclaresTheFacet(): void
    {
        $intent = $this->intent('a gift')->withOverrides(audience: AudienceEnum::FEMALE);

        $declared = $this->service([[10]], facets: ['filterOnly(audience)']);
        $declared->search($this->builder()->build($intent, $declared), 5);
        $this->assertStringContainsString(
            '(audience:"female" OR audience:"unisex" OR audience:"unknown")',
            $this->captured[0]['requests'][0]['filters'],
        );

        Cache::flush();
        $this->captured = [];
        $missing = $this->service([[10]], facets: []);
        $missing->search($this->builder()->build($intent, $missing), 5);
        $this->assertStringNotContainsString('audience', $this->captured[0]['requests'][0]['filters'], 'An undeclared facet fails the whole Algolia request.');
    }

    public function testReadsTheNumericIdNotTheUuidObjectId(): void
    {
        $service = $this->service([[10, 11]]);

        $ids = $service->search($this->builder()->build($this->intent('reloj'), $service), 5);

        $this->assertSame([10, 11], $ids);
        $this->assertSame(
            ProductRecommendationPresenterService::DOCUMENT_FIELDS,
            $this->captured[0]['requests'][0]['attributesToRetrieve'],
            'The response is built from these fields, so nothing is read back from the database.',
        );
    }

    public function testRunsAlternativesInOneCallAndMergesByRank(): void
    {
        $service = $this->service([[10, 12], [11, 10]]);

        $intent = $this->intent('an suv')->withOverrides(query: 'suv', alternativeQueries: ['suv crossover', 'sedan']);
        $ids = $service->search($this->builder()->build($intent, $service), 5);

        $this->assertCount(1, $this->captured, 'Alternatives ride in the same multi-query round trip.');
        $this->assertSame(['suv', 'suv crossover'], array_column($this->captured[0]['requests'], 'query'), 'An alternative that drops the identity term ("sedan") is discarded.');
        $this->assertSame(10, $ids[0], 'Found by both phrasings, so it ranks first.');
    }

    public function testNeverClaimsVectorSupport(): void
    {
        $this->assertFalse($this->service([[1]])->supportsVectors());
    }

    private function intent(string $sentence): ProductIntent
    {
        return ProductIntent::fromSentence($sentence, new IntentLexiconService(app(Apps::class)));
    }

    private function builder(): DiscoveryQueryBuilder
    {
        return new DiscoveryQueryBuilder(app(Apps::class), auth()->user()->getCurrentCompany());
    }

    /**
     * @param list<list<int>> $idsPerRequest
     * @param list<string> $facets
     */
    private function service(array $idsPerRequest, array $facets = ['filterOnly(audience)', 'in_stock']): AlgoliaProductDiscoveryService
    {
        $client = Mockery::mock(SearchClient::class);
        $client->shouldReceive('search')->andReturnUsing(function (array $body) use ($idsPerRequest): array {
            $this->captured[] = $body;

            return [
                'results' => array_map(
                    static fn (array $ids): array => ['hits' => array_map(static fn (int $id): array => ['id' => (string) $id], $ids)],
                    $idsPerRequest,
                ),
            ];
        });
        $client->shouldReceive('getSettings')->andReturn(['attributesForFaceting' => $facets]);

        return new AlgoliaProductDiscoveryService(app(Apps::class), auth()->user()->getCurrentCompany(), $client);
    }
}
