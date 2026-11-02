<?php

declare(strict_types=1);

namespace Tests\Inventory\Recommendations\Enrichment;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Inventory\Importer\Actions\ProductImporterAction;
use Kanvas\Inventory\Importer\DataTransferObjects\ProductImporter;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\DuplicateProductAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Enrichment\Enums\CustomFieldEnum;
use Kanvas\Inventory\Recommendations\Enrichment\Jobs\EnrichProductJob;
use Kanvas\Inventory\Recommendations\Enrichment\Services\ProductEnrichmentFingerprintService;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Inventory\Integration\Imports\ProductImportFixtures;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

#[Group('serial')]
class EnrichProductTriggerTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;
    use ProductImportFixtures;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'inventory', 'social'];

    /**
     * @return array<string, array{0: ?bool, 1: ?bool, 2: bool}>
     */
    public static function flagStates(): array
    {
        return [
            'company on' => [true, null, true],
            'company on over app off' => [true, false, true],
            'app on, company unset' => [null, true, true],
            'unset everywhere' => [null, null, false],
            'company off' => [false, null, false],
            'company off over app on' => [false, true, false],
            'app off, company unset' => [null, false, false],
        ];
    }

    #[DataProvider('flagStates')]
    public function testCreateQueuesEnrichmentOnlyOnAnExplicitTrue(?bool $company, ?bool $app, bool $queued): void
    {
        $tenant = $this->makeCompany();
        $this->applyFlags($tenant, $company, $app);
        Queue::fake();

        $product = $this->createProduct($tenant);

        $this->assertEnrichmentQueued($queued, $product);
    }

    #[DataProvider('flagStates')]
    public function testUpdateQueuesEnrichmentOnlyOnAnExplicitTrue(?bool $company, ?bool $app, bool $queued): void
    {
        $tenant = $this->makeCompany();
        $product = $this->createProduct($tenant);
        $this->applyFlags($tenant, $company, $app);
        Queue::fake();

        $this->updateProduct($product, $tenant);

        $this->assertEnrichmentQueued($queued, $product);
    }

    #[DataProvider('flagStates')]
    public function testDuplicateQueuesEnrichmentOnlyOnAnExplicitTrue(?bool $company, ?bool $app, bool $queued): void
    {
        $tenant = $this->makeCompany();
        $original = $this->createProduct($tenant);
        $this->applyFlags($tenant, $company, $app);
        Queue::fake();

        $duplicate = new DuplicateProductAction($original, auth()->user())->execute();

        Queue::assertNotPushed(
            EnrichProductJob::class,
            fn (EnrichProductJob $job): bool => $job->product->getId() === $original->getId(),
        );
        $this->assertEnrichmentQueued($queued, $duplicate);
    }

    public function testCreateQueuesEnrichmentEvenWhenWorkflowsAreOff(): void
    {
        $company = $this->makeCompany();
        $this->applyFlags($company, true, null);
        Queue::fake();

        $product = $this->createProduct($company, runWorkflow: false);

        $this->assertEnrichmentQueued(true, $product);
    }

    public function testDuplicateQueuesEnrichmentEvenWhenWorkflowsAreOff(): void
    {
        $company = $this->makeCompany();
        $original = $this->createProduct($company);
        $this->applyFlags($company, true, null);
        Queue::fake();

        $duplicate = new DuplicateProductAction($original, auth()->user())
            ->setRunWorkflow(false)
            ->execute();

        $this->assertEnrichmentQueued(true, $duplicate);
    }

    public function testUpdateQueuesEnrichmentWhenTheProductHasWorkflowsDisabled(): void
    {
        $company = $this->makeCompany();
        $product = $this->createProduct($company);
        $product->disableWorkflows();
        $this->applyFlags($company, true, null);
        Queue::fake();

        $this->updateProduct($product, $company);

        $this->assertEnrichmentQueued(true, $product);
    }

    #[DataProvider('flagStates')]
    public function testImportQueuesEnrichmentOnlyOnAnExplicitTrueWithWorkflowsOff(
        ?bool $company,
        ?bool $app,
        bool $queued,
    ): void {
        $tenant = $this->makeCompany();
        $this->applyFlags($tenant, $company, $app);
        $user = auth()->user();
        $appModel = app(Apps::class);
        $slug = 'enrich-import-' . uniqid();
        Queue::fake();

        $imported = new ProductImporterAction(
            ProductImporter::from([
                'name' => 'Imported Drill',
                'slug' => $slug,
                'sku' => 'IMP-' . uniqid(),
                'variants' => [],
            ]),
            $tenant,
            $user,
            $this->makeRegion($tenant, $appModel, $user),
            $appModel,
            runWorkflow: false,
        )->execute();

        $this->assertEnrichmentQueued($queued, $imported);
    }

    public function testAnImportThatTouchesTheSameProductTwiceQueuesOneJob(): void
    {
        $company = $this->makeCompany();
        $this->applyFlags($company, true, null);
        $user = auth()->user();
        $appModel = app(Apps::class);
        $region = $this->makeRegion($company, $appModel, $user);
        $payload = [
            'name' => 'Imported Drill',
            'slug' => 'enrich-twice-' . uniqid(),
            'sku' => 'IMP-' . uniqid(),
            'variants' => [],
        ];
        Queue::fake();

        $first = new ProductImporterAction(
            ProductImporter::from($payload),
            $company,
            $user,
            $region,
            $appModel,
            runWorkflow: false,
        )->execute();
        $this->releaseUniqueLockAfterTest($first);
        new ProductImporterAction(
            ProductImporter::from($payload),
            $company,
            $user,
            $region,
            $appModel,
            runWorkflow: false,
        )->execute();

        Queue::assertPushed(EnrichProductJob::class, 1);
    }

    public function testRepeatedDispatchesForOneProductCollapseIntoOneJob(): void
    {
        $company = $this->makeCompany();
        $product = $this->createProduct($company);
        $this->applyFlags($company, true, null);
        Queue::fake();
        $this->releaseUniqueLockAfterTest($product);

        EnrichProductJob::dispatchIfEnabled($product);
        EnrichProductJob::dispatchIfEnabled($product);
        EnrichProductJob::dispatchIfEnabled($product);

        Queue::assertPushed(EnrichProductJob::class, 1);
    }

    public function testAnAlreadyEnrichedUnchangedProductQueuesNothing(): void
    {
        $company = $this->makeCompany();
        $product = $this->createProduct($company);
        $this->applyFlags($company, true, null);
        $product->set(CustomFieldEnum::ENRICHMENT_HASH->value, ProductEnrichmentFingerprintService::for($product));
        Queue::fake();
        $this->releaseUniqueLockAfterTest($product);

        EnrichProductJob::dispatchIfEnabled($product);

        Queue::assertNotPushed(EnrichProductJob::class, 'A price or stock edit must not cost a queued job.');

        $product->name = $product->name . ' v2';
        $product->saveQuietly();
        EnrichProductJob::dispatchIfEnabled($product);

        Queue::assertPushed(EnrichProductJob::class, 1);
    }

    public function testDifferentProductsAreNotCollapsed(): void
    {
        $company = $this->makeCompany();
        $first = $this->createProduct($company);
        $second = $this->createProduct($company);
        $this->applyFlags($company, true, null);
        Queue::fake();
        $this->releaseUniqueLockAfterTest($first);
        $this->releaseUniqueLockAfterTest($second);

        EnrichProductJob::dispatchIfEnabled($first);
        EnrichProductJob::dispatchIfEnabled($second);

        Queue::assertPushed(EnrichProductJob::class, 2);
    }

    public function testTheJobIsUniquePerProductForAFewMinutes(): void
    {
        $company = $this->makeCompany();
        $product = $this->createProduct($company);

        $job = new EnrichProductJob($product->app, $product);

        $this->assertSame((string) $product->getId(), $job->uniqueId());
        $this->assertSame(300, $job->uniqueFor);
        $this->assertSame('product-enrichment', $job->queue);
    }

    private function assertEnrichmentQueued(bool $queued, Products $product): void
    {
        $this->releaseUniqueLockAfterTest($product);

        if ($queued) {
            Queue::assertPushed(
                EnrichProductJob::class,
                fn (EnrichProductJob $job): bool => $job->product->getId() === $product->getId()
                    && $job->app->getId() === $product->apps_id,
            );

            return;
        }

        Queue::assertNotPushed(EnrichProductJob::class);
    }

    private function makeCompany(): Companies
    {
        $app = app(Apps::class);
        $company = Companies::factory()->create();
        $company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, auth()->user()->getId());
        new InventorySetup($app, auth()->user(), $company)->run();

        $this->beforeApplicationDestroyed(fn () => $company->deleteAllSettings());

        return $company;
    }

    private function applyFlags(Companies $company, ?bool $companyFlag, ?bool $appFlag): void
    {
        app(Apps::class)->del(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value);

        if ($appFlag !== null) {
            $this->setAppSettings([ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value => $appFlag]);
        }

        if ($companyFlag !== null) {
            $company->set(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value, $companyFlag);
        }
    }

    private function createProduct(Companies $company, bool $runWorkflow = true): Products
    {
        $user = auth()->user();

        return new CreateProductAction(
            new Product(
                app: app(Apps::class),
                company: $company,
                user: $user,
                name: 'Drill ' . uniqid(),
                sku: 'DR-' . uniqid(),
            ),
            $user,
        )->setRunWorkflow($runWorkflow)->execute();
    }

    private function updateProduct(Products $product, Companies $company): Products
    {
        $user = auth()->user();

        return new UpdateProductAction(
            $product,
            new Product(
                app: app(Apps::class),
                company: $company,
                user: $user,
                name: $product->name . ' v2',
                sku: 'DR-' . uniqid(),
            ),
            $user,
        )->execute();
    }

    private function releaseUniqueLockAfterTest(Products $product): void
    {
        $key = $this->uniqueLockKey($product);

        $this->beforeApplicationDestroyed(fn () => Cache::lock($key)->forceRelease());
    }

    private function uniqueLockKey(Products $product): string
    {
        return 'laravel_unique_job:' . EnrichProductJob::class . $product->getId();
    }
}
