<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Actions\EnableProductDiscoveryAction;
use Kanvas\Inventory\Recommendations\Contracts\DiscoveryIndexAdapter;
use Kanvas\Inventory\Recommendations\Enrichment\Jobs\EnrichProductJob;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Recommendations\Services\DiscoveryIndexAdapterResolverService;
use Kanvas\Inventory\Recommendations\Services\ProductDiscoveryResolver;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class EnableProductDiscoveryActionTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    protected $connectionsToTransact = [null, 'inventory'];

    private ?Companies $company = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(
            'products_search_engine',
            ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value,
            ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value,
        );
        $app = app(Apps::class);
        $app->del(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value);
        $app->del(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value);
        $this->company = auth()->user()->getCurrentCompany();
        $this->clearCompanyFlags();

        // Not a tearDown() override: that would replace the trait's and leave the app settings unrestored.
        $this->beforeApplicationDestroyed(fn () => $this->clearCompanyFlags());
    }

    private function clearCompanyFlags(): void
    {
        $this->company->del(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value);
        $this->company->del(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function engines(): array
    {
        return [
            'algolia' => [ProductDiscoveryResolver::ENGINE_ALGOLIA],
            'typesense' => [ProductDiscoveryResolver::ENGINE_TYPESENSE],
        ];
    }

    #[DataProvider('engines')]
    public function testPreparesTheIndexOfTheEngineTheAppAlreadyRunsAndNeverSwitchesIt(string $engine): void
    {
        app(Apps::class)->set('products_search_engine', $engine);
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->with(false, false)->andReturn($this->plan(['add field price_min']));

        $result = $this->action($adapter, discovery: true)->execute();

        $this->assertSame($engine, $result['engine']);
        $this->assertSame(EnableProductDiscoveryAction::INDEX_PREPARED, $result['index']['status']);
        $this->assertSame(['add field price_min'], $result['index']['changes']);
        $this->assertSame($engine, app(Apps::class)->get('products_search_engine'));
    }

    public function testReportsTheRefusalInsteadOfFailingAndStillSetsTheFlags(): void
    {
        app(Apps::class)->set('products_search_engine', ProductDiscoveryResolver::ENGINE_ALGOLIA);
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->once()->andReturn([
            'index' => 'product_index',
            'changes' => [],
            'written' => false,
            'refusal' => 'searchableAttributes is empty',
            'proposed' => ['name'],
        ]);

        $result = $this->action($adapter, discovery: true)->execute();

        $this->assertSame(EnableProductDiscoveryAction::INDEX_REFUSED, $result['index']['status']);
        $this->assertSame('searchableAttributes is empty', $result['index']['message']);
        $this->assertTrue((bool) app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testAnIndexErrorIsReportedAsFailedRatherThanThrown(): void
    {
        app(Apps::class)->set('products_search_engine', ProductDiscoveryResolver::ENGINE_ALGOLIA);
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->andThrow(new RuntimeException('bad credentials'));

        $result = $this->action($adapter, discovery: true)->execute();

        $this->assertSame(EnableProductDiscoveryAction::INDEX_FAILED, $result['index']['status']);
        $this->assertSame('bad credentials', $result['index']['message']);
    }

    public function testTheSqlFallbackHasNoIndexToPrepare(): void
    {
        app(Apps::class)->set('products_search_engine', 'database');
        $resolver = Mockery::mock(DiscoveryIndexAdapterResolverService::class);
        $resolver->shouldReceive('resolve')->andReturn(null);

        $result = new EnableProductDiscoveryAction(app: app(Apps::class), discovery: true, adapters: $resolver)->execute();

        $this->assertSame(ProductDiscoveryResolver::ENGINE_SQL, $result['engine']);
        $this->assertSame(EnableProductDiscoveryAction::INDEX_SKIPPED, $result['index']['status']);
    }

    public function testTheRealResolverIsUsedWhenNoneIsInjected(): void
    {
        app(Apps::class)->set('products_search_engine', 'database');

        $result = new EnableProductDiscoveryAction(app: app(Apps::class), discovery: true)->execute();

        $this->assertSame(EnableProductDiscoveryAction::INDEX_SKIPPED, $result['index']['status']);
    }

    public function testTurningFlagsOffLeavesTheIndexAlone(): void
    {
        app(Apps::class)->set('products_search_engine', ProductDiscoveryResolver::ENGINE_ALGOLIA);
        $resolver = Mockery::mock(DiscoveryIndexAdapterResolverService::class);
        $resolver->shouldNotReceive('resolve');

        $result = new EnableProductDiscoveryAction(
            app: app(Apps::class),
            enrichment: false,
            discovery: false,
            adapters: $resolver,
        )->execute();

        $this->assertSame(EnableProductDiscoveryAction::INDEX_SKIPPED, $result['index']['status']);
        $this->assertFalse((bool) app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testAppScopeWritesTheAppSettingsAndOnlyTheOnesGiven(): void
    {
        $result = $this->action($this->unchangedAdapter(), enrichment: true)->execute();

        $app = app(Apps::class);
        $this->assertSame('app', $result['scope']);
        $this->assertTrue((bool) $app->get(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value));
        $this->assertNull($app->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testCompanyScopeWritesTheCompanySettingsAndLeavesTheApp(): void
    {
        $result = $this->action($this->unchangedAdapter(), discovery: false, company: $this->company)->execute();

        $this->assertSame('company', $result['scope']);
        $this->assertFalse((bool) $this->company->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
        $this->assertNull(app(Apps::class)->get(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED->value));
    }

    public function testTurningEnrichmentOnForACompanyQueuesOnlyThatCompaniesBackfill(): void
    {
        app(Apps::class)->set('products_search_engine', 'database');
        $mine = $this->product($this->company);
        $other = $this->product(Companies::factory()->create());
        Queue::fake();

        $result = $this->action($this->unchangedAdapter(), enrichment: true, company: $this->company)->execute();

        $this->assertSame(1, $result['backfill_queued']);
        Queue::assertPushed(EnrichProductJob::class, 1);
        Queue::assertPushed(EnrichProductJob::class, fn (EnrichProductJob $job): bool => $job->product->is($mine) && ! $job->product->is($other));
    }

    public function testNoBackfillWhenEnrichmentIsNotTurnedOnForACompany(): void
    {
        app(Apps::class)->set('products_search_engine', 'database');
        $this->product($this->company);
        Queue::fake();

        $this->action($this->unchangedAdapter(), discovery: true, company: $this->company)->execute();
        $this->action($this->unchangedAdapter(), enrichment: false, company: $this->company)->execute();
        $this->action($this->unchangedAdapter(), enrichment: true)->execute();

        Queue::assertNotPushed(EnrichProductJob::class);
    }

    public function testCompanyForRefusesACompanyThatIsNotOnTheApp(): void
    {
        $this->assertTrue(EnableProductDiscoveryAction::companyFor(app(Apps::class), $this->company->getId())->is($this->company));

        $this->expectException(ValidationException::class);

        EnableProductDiscoveryAction::companyFor(app(Apps::class), 999999999);
    }

    private function adapter(): MockInterface
    {
        return Mockery::mock(DiscoveryIndexAdapter::class);
    }

    private function unchangedAdapter(): MockInterface
    {
        $adapter = $this->adapter();
        $adapter->shouldReceive('ensureFields')->andReturn($this->plan([]));

        return $adapter;
    }

    private function action(
        DiscoveryIndexAdapter $adapter,
        ?bool $enrichment = null,
        ?bool $discovery = null,
        ?Companies $company = null,
    ): EnableProductDiscoveryAction {
        $resolver = Mockery::mock(DiscoveryIndexAdapterResolverService::class);
        $resolver->shouldReceive('resolve')->andReturn($adapter);

        return new EnableProductDiscoveryAction(
            app: app(Apps::class),
            company: $company,
            enrichment: $enrichment,
            discovery: $discovery,
            adapters: $resolver,
        );
    }

    private function product(Companies $company): Products
    {
        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        return $product;
    }

    /**
     * @param list<string> $changes
     *
     * @return array{index: string, changes: list<string>, written: bool, refusal: ?string, proposed: list<string>}
     */
    private function plan(array $changes): array
    {
        return [
            'index' => 'product_index',
            'changes' => $changes,
            'written' => $changes !== [],
            'refusal' => null,
            'proposed' => [],
        ];
    }
}
