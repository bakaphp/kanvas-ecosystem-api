<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\LogRecommendationImpressionAction;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Inventory\Recommendations\Jobs\LogRecommendationImpressionJob;
use Kanvas\Inventory\Recommendations\Models\RecommendationImpression;
use Kanvas\Inventory\Recommendations\Services\IntentLexiconService;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProductDiscoveryQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    private const string DISCOVER = /** @lang GraphQL */ '
        query($input: ProductDiscoveryInput!) {
            discoverProducts(input: $input) {
                request_id
                recommendations {
                    product { id slug name }
                    variants { id name channel { price is_available quantity } }
                }
            }
        }
    ';

    private mixed $originalEngine = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // These cases assert what the SQL path returns. Whichever engine the app
        // happens to be pointed at otherwise would send the query to a live
        // cluster and the assertions would depend on what is indexed there.
        $app = app(Apps::class);
        $this->originalEngine = $app->get('products_search_engine');
        $app->set('products_search_engine', 'database');
    }

    protected function tearDown(): void
    {
        $app = app(Apps::class);

        is_string($this->originalEngine)
            ? $app->set('products_search_engine', $this->originalEngine)
            : $app->del('products_search_engine');

        parent::tearDown();
    }

    public function testReturnsResultsAndAnAttributionUuid(): void
    {
        Queue::fake();
        $this->makeProduct('Reloj de lujo');

        $response = $this->graphQL(self::DISCOVER, ['input' => ['query' => 'reloj']])
            ->assertSuccessful();

        $result = $response->json('data.discoverProducts');

        $this->assertNotEmpty($result['request_id']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $result['request_id']);
        $this->assertNotEmpty($result['recommendations']);
        $this->assertSame('Reloj de lujo', $result['recommendations'][0]['product']['name']);
    }

    public function testExposesChannelAvailabilityForUnpricedProducts(): void
    {
        Queue::fake();
        $this->makeProduct('Cartera artesanal');

        $variant = $this->graphQL(self::DISCOVER, ['input' => ['query' => 'cartera']])
            ->assertSuccessful()
            ->json('data.discoverProducts.recommendations.0.variants.0');

        // Unpriced items are returned flagged unavailable rather than hidden —
        // the storefront decides how to present them.
        $this->assertFalse($variant['channel']['is_available']);
        $this->assertNull($variant['channel']['price']);
    }

    public function testLogsAnImpressionForEveryQuery(): void
    {
        Queue::fake();
        $this->makeProduct('Perfume floral');

        $this->graphQL(self::DISCOVER, [
            'input' => ['query' => 'perfume', 'session_id' => 'sess-abc'],
        ])->assertSuccessful();

        Queue::assertPushed(
            LogRecommendationImpressionJob::class,
            fn (LogRecommendationImpressionJob $job): bool => $job->query === 'perfume'
                && $job->sessionId === 'sess-abc'
                && $job->productIds !== [],
        );
    }

    public function testLogsEvenWhenNothingMatched(): void
    {
        Queue::fake();

        $this->graphQL(self::DISCOVER, [
            'input' => ['query' => 'zzzzzznotacatalogword'],
        ])->assertSuccessful();

        // A no-hit query is the single most useful signal for spotting catalog
        // gaps, so it must be recorded, not skipped for being empty.
        Queue::assertPushed(
            LogRecommendationImpressionJob::class,
            fn (LogRecommendationImpressionJob $job): bool => $job->productIds === [],
        );
    }

    public function testImpressionRowPreservesRankOrderAndParsedIntent(): void
    {
        $app = app(Apps::class);
        $user = $this->actingUser();
        $company = $user->getCurrentCompany();
        $intent = ProductIntent::fromSentence('un regalo menos de $50', new IntentLexiconService($app));

        $impression = new LogRecommendationImpressionAction(
            app: $app,
            company: $company,
            recommendationUuid: 'a1b2c3d4-0000-4000-8000-000000000001',
            query: 'Un Regalo MENOS de $50',
            productIds: [42, 7, 13],
            usersId: $user->getId(),
            engine: 'sql',
            intent: $intent,
        )->execute();

        $stored = RecommendationImpression::query()
            ->fromApp($app)
            ->where('recommendation_uuid', 'a1b2c3d4-0000-4000-8000-000000000001')
            ->firstOrFail();

        $this->assertSame([42, 7, 13], $stored->product_ids, 'Rank position is half the signal — order must survive the round trip.');
        $this->assertSame(3, $stored->results_count);
        // JSON has no float/int distinction, so 50.0 comes back as 50. The
        // intent blob is analytical, not arithmetic, so the value is what matters.
        $this->assertEquals(50.0, $stored->intent['max_price']);
        $this->assertSame('un regalo menos de $50', $stored->query_normalized, 'Normalized so the popular-query report groups casing variants.');
        $this->assertSame('Un Regalo MENOS de $50', $stored->query_raw);
        $this->assertSame($impression->getKey(), $stored->getKey());
    }

    private const string DISCOVER_ANON = /** @lang GraphQL */ '
        query($input: ProductDiscoveryInput!) {
            discoverProducts(input: $input) {
                request_id
                recommendations { product { id name } }
            }
        }
    ';

    public function testAnonymousShopperCanSearchWithTheAppKey(): void
    {
        Queue::fake();
        $this->makeProduct('Reloj de lujo');

        $result = $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'request_id' => (string) Str::uuid(),
                'company_id' => (string) $this->actingUser()->getCurrentCompany()->getId(),
                'session_id' => 'anon-visitor-1',
            ]],
            [],
            $this->appKeyHeader(),
        )->assertSuccessful()->json('data.discoverProducts');

        $this->assertNotEmpty($result['request_id']);
        $this->assertSame('Reloj de lujo', $result['recommendations'][0]['product']['name']);
    }

    public function testAnonymousSearchLogsTheImpressionWithSessionButNoUser(): void
    {
        Queue::fake();
        $this->makeProduct('Perfume floral');

        $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'perfume',
                'request_id' => (string) Str::uuid(),
                'company_id' => (string) $this->actingUser()->getCurrentCompany()->getId(),
                'session_id' => 'anon-visitor-2',
            ]],
            [],
            $this->appKeyHeader(),
        )->assertSuccessful();

        // The session id is the only thread back to this visitor — it is what
        // lets their pre-signup searches be stitched to an account later.
        Queue::assertPushed(
            LogRecommendationImpressionJob::class,
            fn (LogRecommendationImpressionJob $job): bool => $job->sessionId === 'anon-visitor-2'
                && $job->usersId === null,
        );
    }

    public function testAppKeySearchUsesTheRequestedCompanyNotAnImplicitOne(): void
    {
        Queue::fake();

        // The guarded mutation also accepts an app key, but with no user it
        // resolves the company from a fallback and searches whichever tenant
        // that lands on — verified against a live app key, which answered from
        // an unrelated company and returned nothing. The public path takes the
        // company explicitly so a storefront gets its own catalogue.
        $app = app(Apps::class);
        $originalCrossCompany = $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, 0);

        $ownProduct = $this->makeProduct('Reloj de lujo');
        $requestedCompany = $this->actingUser()->getCurrentCompany();

        $otherCompany = Companies::factory()->create();
        Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($otherCompany->getId())
            ->create(['name' => 'Reloj de otra empresa', 'is_published' => 1, 'is_deleted' => 0]);

        $names = $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'request_id' => (string) Str::uuid(),
                'company_id' => (string) $requestedCompany->getId(),
            ]],
            [],
            $this->appKeyHeader(),
        )->assertSuccessful()->json('data.discoverProducts.recommendations.*.product.name');

        $this->assertContains($ownProduct->name, $names);
        $this->assertNotContains('Reloj de otra empresa', $names);

        Queue::assertPushed(
            LogRecommendationImpressionJob::class,
            fn (LogRecommendationImpressionJob $job): bool => $job->company->getId() === $requestedCompany->getId(),
        );

        $app->set(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value, $originalCrossCompany);
    }

    public function testDifferentSearchesInOneSessionEachGetTheirOwnRow(): void
    {
        $app = app(Apps::class);
        $company = $this->actingUser()->getCurrentCompany();

        // session_id is the visitor; request_id is one search. A shopper who
        // searches twice in a session must not overwrite their first search.
        foreach (['reloj', 'perfume'] as $query) {
            new LogRecommendationImpressionAction(
                app: $app,
                company: $company,
                recommendationUuid: (string) Str::uuid(),
                query: $query,
                productIds: [1],
                sessionId: 'anon-same-session',
            )->execute();
        }

        $rows = RecommendationImpression::query()
            ->fromApp($app)
            ->where('session_id', 'anon-same-session')
            ->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['reloj', 'perfume'], $rows->pluck('query_normalized')->all());
    }

    public function testReusingOneRequestIdForADifferentQueryKeepsTheFirstSearch(): void
    {
        $app = app(Apps::class);
        $company = $this->actingUser()->getCurrentCompany();
        $requestId = (string) Str::uuid();

        // A client generating one id per page load instead of per search would
        // otherwise delete its earlier search. First report wins.
        new LogRecommendationImpressionAction(
            app: $app,
            company: $company,
            recommendationUuid: $requestId,
            query: 'reloj',
            productIds: [1, 2],
        )->execute();

        new LogRecommendationImpressionAction(
            app: $app,
            company: $company,
            recommendationUuid: $requestId,
            query: 'perfume',
            productIds: [9],
        )->execute();

        $rows = RecommendationImpression::query()
            ->fromApp($app)
            ->where('recommendation_uuid', $requestId)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('reloj', $rows->first()->query_normalized);
        $this->assertSame([1, 2], $rows->first()->product_ids);
    }

    public function testAppKeyRequestWithoutARequestIdIsRejected(): void
    {
        Queue::fake();
        $this->makeProduct('Reloj de lujo');

        // A server-minted id would be frozen into the client cache and every
        // repeat of a cached search would report the first search's id.
        $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'company_id' => (string) $this->actingUser()->getCurrentCompany()->getId(),
            ]],
            [],
            $this->appKeyHeader(),
        )->assertJsonStructure(['errors']);

        Queue::assertNotPushed(LogRecommendationImpressionJob::class);
    }

    public function testRejectsARequestIdThatIsNotAUuid(): void
    {
        Queue::fake();

        // It lands in a unique, indexed column — free text would let a caller
        // fill the impression log with junk.
        $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'request_id' => 'not-a-uuid',
                'company_id' => (string) $this->actingUser()->getCurrentCompany()->getId(),
            ]],
            [],
            $this->appKeyHeader(),
        )->assertJsonStructure(['errors']);

        Queue::assertNotPushed(LogRecommendationImpressionJob::class);
    }

    public function testEchoesBackTheClientSuppliedRequestId(): void
    {
        Queue::fake();
        $this->makeProduct('Reloj de lujo');
        $requestId = (string) Str::uuid();

        $result = $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'request_id' => $requestId,
                'company_id' => (string) $this->actingUser()->getCurrentCompany()->getId(),
            ]],
            [],
            $this->appKeyHeader(),
        )->assertSuccessful()->json('data.discoverProducts');

        $this->assertSame($requestId, $result['request_id']);

        Queue::assertPushed(
            LogRecommendationImpressionJob::class,
            fn (LogRecommendationImpressionJob $job): bool => $job->recommendationUuid === $requestId,
        );
    }

    public function testRepeatingARequestIdRecordsOneSearchNotTwo(): void
    {
        $app = app(Apps::class);
        $company = $this->actingUser()->getCurrentCompany();
        $requestId = (string) Str::uuid();

        // A cached result carries its id, so the same search can be reported
        // twice. One row per search is the point, and the queued job must not
        // die on the unique index.
        foreach ([[1, 2], [3]] as $ids) {
            new LogRecommendationImpressionAction(
                app: $app,
                company: $company,
                recommendationUuid: $requestId,
                query: 'reloj',
                productIds: $ids,
            )->execute();
        }

        $rows = RecommendationImpression::query()
            ->fromApp($app)
            ->where('recommendation_uuid', $requestId)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame([3], $rows->first()->product_ids);
    }

    public function testAppKeyRequestWithoutACompanyIsRejected(): void
    {
        Queue::fake();
        $this->makeProduct('Reloj de lujo');

        // Left implicit, an app-key request resolves a fallback user and answers
        // from whichever company that lands on — observed live returning results
        // for an unrelated tenant. Failing loudly beats answering wrongly.
        $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => ['query' => 'reloj']],
            [],
            $this->appKeyHeader(),
        )->assertJsonStructure(['errors']);

        Queue::assertNotPushed(LogRecommendationImpressionJob::class);
    }

    public function testAnonymousSearchCannotReachACompanyOutsideTheAppKeysApp(): void
    {
        Queue::fake();

        // The factory links a new company to the current app, so the link has to
        // be removed to get a genuinely foreign one. Without the app-scoped
        // lookup an app key would be a read primitive over every catalogue on
        // the platform.
        $foreign = Companies::factory()->create();
        UserCompanyApps::where('companies_id', $foreign->getId())->delete();

        $this->graphQL(
            self::DISCOVER_ANON,
            ['input' => [
                'query' => 'reloj',
                'request_id' => (string) Str::uuid(),
                'company_id' => (string) $foreign->getId(),
            ]],
            [],
            $this->appKeyHeader(),
        )->assertJsonStructure(['errors']);

        // Scoped to the impression job: creating the fixture company queues
        // unrelated scout/listener work, so a blanket assertion would be noise.
        Queue::assertNotPushed(LogRecommendationImpressionJob::class);
    }

    public function testLoggedInUserCannotNameACompanyTheyDoNotBelongTo(): void
    {
        Queue::fake();

        // Otherwise any member of the app could read a sibling company's catalog
        // just by passing its id.
        $strangersCompany = Companies::factory()->create();

        $this->graphQL(self::DISCOVER_ANON, ['input' => [
            'query' => 'reloj',
            'request_id' => (string) Str::uuid(),
            'company_id' => (string) $strangersCompany->getId(),
        ]])->assertJsonStructure(['errors']);

        Queue::assertNotPushed(LogRecommendationImpressionJob::class);
    }

    private function appKeyHeader(): array
    {
        $app = app(Apps::class);

        return [AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $app->keys()->first()->client_secret_id];
    }

    public function testRequiresAuthentication(): void
    {
        $this->graphQL(self::DISCOVER, ['input' => ['query' => 'reloj']], [], ['HTTP_Authorization' => 'Bearer invalid'])
            ->assertJsonStructure(['errors']);
    }

    private function actingUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function makeProduct(string $name): Products
    {
        $app = app(Apps::class);
        $company = $this->actingUser()->getCurrentCompany();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => $name, 'is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }
}
