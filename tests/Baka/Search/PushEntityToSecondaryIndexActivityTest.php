<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\Activities\PushEntityToSecondaryIndexActivity;
use Baka\Search\Contracts\SecondaryIndexServiceInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

final class PushEntityToSecondaryIndexActivityTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testMissingIndexNameFailsWorkflowWithoutAnyIntegrationLookup(): void
    {
        $product = $this->createProduct();

        $result = $this->activity()->execute($product, app(Apps::class), []);

        $this->assertFalse($result['result']);
        $this->assertSame('index_name is required in params', $result['message']);
    }

    public function testUnknownSearchEngineIsReportedAsAFailureNotAnUncaughtThrow(): void
    {
        $product = $this->createProduct();
        $this->setUpInternalIntegration($product);

        $result = $this->activity()->execute($product, app(Apps::class), [
            'index_name' => 'whatever_index',
            'search_engine' => 'meilisearch',
        ]);

        $this->assertFalse($result['result']);
        $this->assertStringContainsString('not implemented', $result['message']);
    }

    public function testIndexesTheEntityWhenItShouldBeSearchable(): void
    {
        $product = $this->createProduct();
        $this->setUpInternalIntegration($product);

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->once())
            ->method('indexEntity')
            ->with($product, 'popular_index');
        $service->expects($this->never())->method('removeEntity');

        $result = $this->activityWithService($service)->execute($product, app(Apps::class), [
            'index_name' => 'popular_index',
            'search_engine' => 'typesense',
        ]);

        $this->assertTrue($result['result']);
        $this->assertSame('popular_index', $result['index']);
    }

    public function testRemovesTheEntityInsteadWhenItShouldNotBeSearchable(): void
    {
        $product = $this->createProduct();
        $product->is_published = 0;
        $product->save();
        $this->setUpInternalIntegration($product);

        $service = $this->createMock(SecondaryIndexServiceInterface::class);
        $service->expects($this->once())
            ->method('removeEntity')
            ->with($product, 'popular_index');
        $service->expects($this->never())->method('indexEntity');

        $result = $this->activityWithService($service)->execute($product, app(Apps::class), [
            'index_name' => 'popular_index',
            'search_engine' => 'algolia',
        ]);

        $this->assertTrue($result['result']);
        $this->assertStringContainsString('removed', $result['message']);
    }

    private function activity(): PushEntityToSecondaryIndexActivity
    {
        return new PushEntityToSecondaryIndexActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        );
    }

    private function activityWithService(SecondaryIndexServiceInterface $service): PushEntityToSecondaryIndexActivity
    {
        return new class (0, now()->toDateTimeString(), new StoredWorkflow(), [], $service) extends PushEntityToSecondaryIndexActivity {
            public function __construct(
                int $index,
                string $now,
                StoredWorkflow $storedWorkflow,
                array $arguments,
                private readonly SecondaryIndexServiceInterface $fakeService,
            ) {
                parent::__construct($index, $now, $storedWorkflow, $arguments);
            }

            protected function resolveSecondaryIndexService(string $searchEngine, Apps $app): SecondaryIndexServiceInterface
            {
                return $this->fakeService;
            }
        };
    }

    private function setUpInternalIntegration(Products $product): void
    {
        $user = auth()->user();

        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            'Kanvas\\Connectors\\Internal\\Handlers\\InternalHandler',
            $product->company,
            $user
        );
    }

    private function createProduct(): Products
    {
        $company = Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        $product->load('variants');

        return $product;
    }
}
