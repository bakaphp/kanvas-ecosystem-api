<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteDataAction;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportStatusEnum;
use Kanvas\Connectors\NetSuite\Jobs\ImportNetSuiteDataJob;
use Kanvas\Connectors\NetSuite\Services\NetSuiteImportStatusService;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

class ImportNetSuiteDataJobTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    private Companies $company;
    private Users $kanvasUser;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $this->freshCompany();
        Queue::fake();
    }

    public function testAChunkIsImportedAndTheRestIsQueued(): void
    {
        $ids = array_map(fn (int $i) => 'SKU-' . $i, range(1, ImportNetSuiteDataJob::CHUNK_SIZE + 20));
        $action = $this->fakeAction($ids);
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue('901');

        $this->job($action, ImportEntityEnum::PRODUCTS, savedSearchId: '901')->handle();

        $this->assertSame([ImportNetSuiteDataJob::CHUNK_SIZE], array_map('count', $action->chunks));
        $this->assertSame([[ImportEntityEnum::PRODUCTS, '901']], $action->listed);

        Queue::assertPushed(
            ImportNetSuiteDataJob::class,
            fn (ImportNetSuiteDataJob $next) => $next->offset === ImportNetSuiteDataJob::CHUNK_SIZE
                && $next->entity === ImportEntityEnum::PRODUCTS
                && $next->savedSearchId === '901'
        );

        $status = $this->importStatus(ImportEntityEnum::PRODUCTS)->current();
        $this->assertSame(ImportStatusEnum::RUNNING->value, $status['status']);
        $this->assertSame(ImportNetSuiteDataJob::CHUNK_SIZE + 20, $status['total']);
        $this->assertSame(ImportNetSuiteDataJob::CHUNK_SIZE, $status['processed']);
        $this->assertSame(ImportNetSuiteDataJob::CHUNK_SIZE, $status['created']);
    }

    public function testTheLastChunkCompletesTheRunWithoutListingAgain(): void
    {
        $ids = array_map(fn (int $i) => 'SKU-' . $i, range(1, ImportNetSuiteDataJob::CHUNK_SIZE + 20));
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null);
        $this->job($this->fakeAction($ids), ImportEntityEnum::PRODUCTS)->handle();

        $second = $this->fakeAction($ids);
        $this->job($second, ImportEntityEnum::PRODUCTS, offset: ImportNetSuiteDataJob::CHUNK_SIZE)->handle();

        $this->assertSame([], $second->listed, 'The id list is listed once per run, not once per chunk.');
        $this->assertSame([20], array_map('count', $second->chunks));
        Queue::assertPushed(ImportNetSuiteDataJob::class, 1);

        $status = $this->importStatus(ImportEntityEnum::PRODUCTS)->current();
        $this->assertSame(ImportStatusEnum::COMPLETED->value, $status['status']);
        $this->assertSame(ImportNetSuiteDataJob::CHUNK_SIZE + 20, $status['processed']);
        $this->assertNotNull($status['finished_at']);
    }

    public function testNothingToImportCompletesAtOnce(): void
    {
        $this->importStatus(ImportEntityEnum::CUSTOMERS)->queue(null);

        $this->job($this->fakeAction([]), ImportEntityEnum::CUSTOMERS)->handle();

        Queue::assertNothingPushed();
        $status = $this->importStatus(ImportEntityEnum::CUSTOMERS)->current();
        $this->assertSame(ImportStatusEnum::COMPLETED->value, $status['status']);
        $this->assertSame(0, $status['total']);
    }

    public function testStockRunsInOneJob(): void
    {
        $action = $this->fakeAction([]);
        $this->importStatus(ImportEntityEnum::STOCK)->queue('576');

        $this->job(
            $action,
            ImportEntityEnum::STOCK,
            savedSearchId: '576',
            createMissing: true
        )->handle();

        $this->assertSame([['576', true]], $action->stockRuns);
        Queue::assertNothingPushed();

        $status = $this->importStatus(ImportEntityEnum::STOCK)->current();
        $this->assertSame(ImportStatusEnum::COMPLETED->value, $status['status']);
        $this->assertSame(['processed' => 7], $status['summary']);
    }

    public function testAFailedJobMarksTheRunFailed(): void
    {
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null);

        $this->job($this->fakeAction([]), ImportEntityEnum::PRODUCTS)
            ->failed(new RuntimeException('NetSuite configuration is missing.'));

        $status = $this->importStatus(ImportEntityEnum::PRODUCTS)->current();
        $this->assertSame(ImportStatusEnum::FAILED->value, $status['status']);
        $this->assertSame('NetSuite configuration is missing.', $status['error']);
    }

    public function testAContinuationWhoseIdListExpiredFailsInsteadOfFinishingEmpty(): void
    {
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null);

        $this->expectException(RuntimeException::class);

        $this->job($this->fakeAction(['SKU-1']), ImportEntityEnum::PRODUCTS, offset: ImportNetSuiteDataJob::CHUNK_SIZE)
            ->handle();
    }

    public function testTheChosenLocationsTravelWithEveryChunk(): void
    {
        $ids = array_map(fn (int $i) => 'SKU-' . $i, range(1, ImportNetSuiteDataJob::CHUNK_SIZE + 5));
        $action = $this->fakeAction($ids);
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null, ['7', '4']);

        $this->job($action, ImportEntityEnum::PRODUCTS, locationIds: ['7', '4'])->handle();

        $this->assertSame([['7', '4'], ['7', '4']], $action->locations, 'Both the listing and the chunk use the chosen locations.');
        Queue::assertPushed(
            ImportNetSuiteDataJob::class,
            fn (ImportNetSuiteDataJob $next) => $next->locationIds === ['7', '4']
        );
    }

    public function testAProductImportFromSeveralLocationsQueuesTheStockImportWhenItEnds(): void
    {
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null, ['7', '4']);

        $this->job($this->fakeAction(['SKU-1']), ImportEntityEnum::PRODUCTS, locationIds: ['7', '4'])
            ->handle();

        Queue::assertPushed(
            ImportNetSuiteDataJob::class,
            fn (ImportNetSuiteDataJob $job) => $job->entity === ImportEntityEnum::STOCK && $job->savedSearchId === '574'
        );
        $stock = $this->importStatus(ImportEntityEnum::STOCK)->current();
        $products = $this->importStatus(ImportEntityEnum::PRODUCTS)->current();
        $this->assertSame(ImportStatusEnum::QUEUED->value, $stock['status']);
        $this->assertSame(['stock' => 'queued'], $products['summary']);
    }

    public function testWithoutAStockSavedSearchTheProductImportSaysStockWasNotQueued(): void
    {
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null, ['7', '4']);

        $this->job($this->fakeAction(['SKU-1']), ImportEntityEnum::PRODUCTS, locationIds: ['7', '4'])
            ->handle();

        Queue::assertNothingPushed();
        $summary = $this->importStatus(ImportEntityEnum::PRODUCTS)->current()['summary'];
        $this->assertSame('skipped', $summary['stock']);
        $this->assertStringContainsString('NET_SUITE_STOCK_SAVED_SEARCH_ID', $summary['reason']);
    }

    public function testAProductImportFromOneLocationDoesNotNeedTheStockImport(): void
    {
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');
        $this->importStatus(ImportEntityEnum::PRODUCTS)->queue(null, ['7']);

        $this->job($this->fakeAction(['SKU-1']), ImportEntityEnum::PRODUCTS, locationIds: ['7'])->handle();

        Queue::assertNothingPushed();
        $this->assertNull($this->importStatus(ImportEntityEnum::PRODUCTS)->current()['summary']);
    }

    private function importStatus(ImportEntityEnum $entity): NetSuiteImportStatusService
    {
        return new NetSuiteImportStatusService($this->company, $entity);
    }

    private function job(
        ImportNetSuiteDataAction $action,
        ImportEntityEnum $entity,
        ?string $savedSearchId = null,
        bool $createMissing = false,
        int $offset = 0,
        array $locationIds = []
    ): ImportNetSuiteDataJob {
        $job = new class (
            app(Apps::class),
            $this->company,
            $this->kanvasUser,
            $entity,
            $savedSearchId,
            $createMissing,
            $offset,
            $locationIds
        ) extends ImportNetSuiteDataJob {
            public ImportNetSuiteDataAction $fake;

            protected function action(): ImportNetSuiteDataAction
            {
                return $this->fake;
            }
        };
        $job->fake = $action;

        return $job;
    }

    private function fakeAction(array $ids): ImportNetSuiteDataAction
    {
        return new class ($ids) extends ImportNetSuiteDataAction {
            public array $listed = [];
            public array $chunks = [];
            public array $stockRuns = [];
            public array $locations = [];

            public function __construct(private array $ids)
            {
            }

            public function listIds(ImportEntityEnum $entity, ?string $savedSearchId, array $locationIds = []): array
            {
                $this->listed[] = [$entity, $savedSearchId];
                $this->locations[] = $locationIds;

                return $this->ids;
            }

            public function importRecords(ImportEntityEnum $entity, array $ids, array $locationIds = []): array
            {
                $this->chunks[] = $ids;
                $this->locations[] = $locationIds;

                return ['created' => count($ids), 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
            }

            public function importStock(?string $savedSearchId, bool $createMissing): array
            {
                $this->stockRuns[] = [$savedSearchId, $createMissing];

                return ['processed' => 7];
            }
        };
    }
}
