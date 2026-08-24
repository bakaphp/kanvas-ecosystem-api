<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Inventory\Recommendations\Services\TypesenseProductDiscoveryService;
use Mockery;
use Tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\MultiSearch;

class TypesenseProductDiscoveryServiceTest extends TestCase
{
    use DatabaseTransactions;

    private array $capturedSearches = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The collection schema is cached per collection+field, so a sibling case
        // asserting the field is absent would otherwise read this one's answer.
        Cache::flush();
    }

    public function testSendsEverySearchInOneMultiSearchRoundTrip(): void
    {
        $service = $this->service($this->response([[10, 11]]));

        $service->search($this->intent('un regalo elegante'), 5, [0.1, 0.2]);

        $this->assertCount(1, $this->capturedSearches, 'Two searches must ride in ONE call — separate calls pay the network round trip twice.');
        $this->assertCount(2, $this->capturedSearches[0]['searches']);
    }

    public function testOmitsTheTasteSearchWhenNoVectorIsSupplied(): void
    {
        $service = $this->service($this->response([[10]]));

        $service->search($this->intent('un regalo elegante'), 5);

        $this->assertCount(1, $this->capturedSearches[0]['searches']);
    }

    public function testScopesTheCandidatePoolToTheTenant(): void
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $service = $this->service($this->response([[10]]), $app, $company);

        $service->search($this->intent('reloj'), 5);

        $filter = $this->capturedSearches[0]['searches'][0]['filter_by'];
        $this->assertStringContainsString('apps_id:=' . $app->getId(), $filter);
    }

    public function testTranslatesPriceIntentIntoFilters(): void
    {
        $service = $this->service($this->response([[10]]));

        $service->search($this->intent('un reloj under $80'), 5);

        $this->assertStringContainsString('price:<=80', $this->capturedSearches[0]['searches'][0]['filter_by']);
    }

    public function testPriceFloorStillAdmitsProductsWithAnUnknownPrice(): void
    {
        $service = $this->service($this->response([[10]]));

        // "de lujo" sets a floor with no explicit number. Unpriced products index
        // price as 0, so a bare `price:>=N` would drop the whole catalogue —
        // observed against a real 172-product index that returned nothing.
        $service->search($this->intent('algo de lujo'), 5);

        $filter = $this->capturedSearches[0]['searches'][0]['filter_by'];
        $this->assertStringContainsString('|| price:=0', $filter);
    }

    public function testFiltersToTheRecipientTheSentenceNames(): void
    {
        $service = $this->service($this->response([[10]]));

        $service->search($this->intent('a gift for my girlfriend'), 5);

        $filter = $this->capturedSearches[0]['searches'][0]['filter_by'];

        // Neutral and un-enriched products ride along, or the filter would throw
        // away most of a catalog to answer a question about gender.
        $this->assertStringContainsString('audience:[female, unisex, unknown]', $filter);
    }

    public function testTakesTheLongestRecipientMatchNotTheFirst(): void
    {
        $service = $this->service($this->response([[10]]));

        // "grandmother" contains "mother"; a first-match parse reads a query for a
        // grandmother as one for a mother and filters on the wrong audience.
        $service->search($this->intent('a gift for my grandmother'), 5);

        $this->assertStringContainsString('audience:[senior,', $this->capturedSearches[0]['searches'][0]['filter_by']);
    }

    public function testReadsTheRecipientFromTheTenantsOwnLanguage(): void
    {
        // Shipped config is English only. A Spanish storefront gets nothing out of
        // this filter until it adds its own terms — which is the whole point of
        // the lexicon being merged rather than replaced.
        $app = app(Apps::class);
        $original = $app->get(ConfigurationEnum::INTENT_LEXICON->value);
        $app->set(ConfigurationEnum::INTENT_LEXICON->value, [
            'audience_female' => ['novia', 'mama', 'suegra'],
        ]);

        try {
            $service = $this->service($this->response([[10]]), $app);
            $service->search($this->intent('un regalo para mi suegra'), 5);

            $this->assertStringContainsString(
                'audience:[female,',
                $this->capturedSearches[0]['searches'][0]['filter_by'],
            );
        } finally {
            $app->set(ConfigurationEnum::INTENT_LEXICON->value, $original ?? []);
        }
    }

    public function testSkipsTheAudienceFilterWhenTheCollectionDoesNotDeclareTheField(): void
    {
        // Scout creates collections but never migrates them, so a collection built
        // before the field exists cannot be filtered on it — and Typesense answers
        // an undeclared field with NOTHING, not with everything.
        $service = $this->service($this->response([[10]]), collectionFields: ['search_blurb', 'price']);

        $ids = $service->search($this->intent('a gift for my girlfriend'), 5);

        $this->assertStringNotContainsString('audience:', $this->capturedSearches[0]['searches'][0]['filter_by']);
        $this->assertSame([10], $ids);
    }

    public function testDoesNotFilterOnAudienceWhenTheSentenceNamesNoOne(): void
    {
        $service = $this->service($this->response([[10]]));

        $service->search($this->intent('algo bonito'), 5);

        $this->assertStringNotContainsString('audience:', $this->capturedSearches[0]['searches'][0]['filter_by']);
    }

    public function testOmitsTheVectorQueryWhenTheCollectionHasNoEmbeddingField(): void
    {
        // Naming a field the collection does not declare makes Typesense reject
        // the WHOLE search, so the vector half has to be opt-in via query_by.
        config(['inventory-discovery.typesense_query_by' => 'search_blurb,name,description']);

        $service = $this->service($this->response([[10]]));
        $service->search($this->intent('algo bonito'), 5);

        $this->assertArrayNotHasKey('vector_query', $this->capturedSearches[0]['searches'][0]);
    }

    public function testAddsTheVectorQueryOnceEmbeddingIsInQueryBy(): void
    {
        config(['inventory-discovery.typesense_query_by' => 'search_blurb,name,embedding']);

        $service = $this->service($this->response([[10]]));
        $service->search($this->intent('algo bonito'), 5);

        $this->assertStringContainsString(
            'embedding:([], alpha:',
            $this->capturedSearches[0]['searches'][0]['vector_query'],
        );
    }

    public function testFusesTheTwoRankingsSoAgreementWins(): void
    {
        // Semantic ranks 10 first; taste ranks 11 first but also likes 10.
        // Product 10 appears in both lists, so reciprocal-rank fusion floats it
        // above a product only one search loved.
        $service = $this->service($this->response([
            [10, 12],
            [11, 10],
        ]));

        $ids = $service->search($this->intent('algo bonito'), 5, [0.1]);

        $this->assertSame(10, $ids[0]);
        $this->assertContains(11, $ids);
        $this->assertContains(12, $ids);
    }

    public function testRespectsTheRequestedLimit(): void
    {
        $service = $this->service($this->response([[10, 11, 12, 13, 14]]));

        $this->assertCount(2, $service->search($this->intent('algo'), 2));
    }

    public function testExcludesTheEmbeddingFromTheResponsePayload(): void
    {
        $service = $this->service($this->response([[10]]));
        $service->search($this->intent('algo'), 5);

        // Without this every hit ships a 384-float array back.
        $this->assertSame('embedding', $this->capturedParams['exclude_fields'] ?? null);
    }

    private array $capturedParams = [];

    private function intent(string $sentence): ProductIntent
    {
        return ProductIntent::fromSentence($sentence, new IntentLexiconService(app(Apps::class)));
    }

    /**
     * @param array<int, list<int>> $idsPerSearch
     */
    private function response(array $idsPerSearch): array
    {
        return [
            'results' => array_map(
                static fn (array $ids): array => [
                    'hits' => array_map(
                        static fn (int $id): array => ['document' => ['id' => (string) $id]],
                        $ids,
                    ),
                ],
                $idsPerSearch,
            ),
        ];
    }

    /**
     * @param list<string> $collectionFields
     */
    private function service(
        array $response,
        ?Apps $app = null,
        ?Companies $company = null,
        array $collectionFields = ['search_blurb', 'price', 'in_stock', 'audience'],
    ): TypesenseProductDiscoveryService {
        $multiSearch = Mockery::mock(MultiSearch::class);
        $multiSearch->shouldReceive('perform')
            ->andReturnUsing(function (array $body, array $params) use ($response): array {
                $this->capturedSearches[] = $body;
                $this->capturedParams = $params;

                return $response;
            });

        $collection = Mockery::mock(Collection::class);
        $collection->shouldReceive('retrieve')->andReturn([
            'fields' => array_map(static fn (string $name): array => ['name' => $name], $collectionFields),
        ]);

        $collections = Mockery::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->andReturn($collection);

        $client = Mockery::mock(Client::class);
        $client->multiSearch = $multiSearch;
        $client->collections = $collections;

        return new TypesenseProductDiscoveryService(
            $app ?? app(Apps::class),
            $company ?? Companies::factory()->create(),
            $client,
        );
    }
}
