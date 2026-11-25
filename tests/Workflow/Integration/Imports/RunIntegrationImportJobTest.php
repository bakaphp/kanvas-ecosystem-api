<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Importers\IntegrationImporter;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use RuntimeException;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;
use Tests\Workflow\Integration\Imports\Fakes\FakeChunkedImporter;
use Tests\Workflow\Integration\Imports\Fakes\FakeImportRegistry;
use Tests\Workflow\Integration\Imports\Fakes\FakeSingleRunImporter;

final class RunIntegrationImportJobTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;
    use ImportsTestSetup;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private IntegrationsCompany $row;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeImportRegistry::reset();
        $this->row = $this->createIntegrationRows();
    }

    public function testAChunkIsImportedAndTheRestIsQueued(): void
    {
        FakeImportRegistry::$items = range(1, 120);
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::RUNNING, $run->status);
        $this->assertSame(120, $run->total);
        $this->assertSame(100, $run->processed);
        $this->assertCount(100, FakeImportRegistry::$imported);
        Queue::assertPushed(RunIntegrationImportJob::class, fn ($job) => $job->offset === 100);
    }

    public function testTheLastChunkCallsCompletedAndStoresItsSummary(): void
    {
        FakeImportRegistry::$items = range(1, 120);
        FakeImportRegistry::$completed = ['x' => 1];
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();
        $this->job($run->fresh(), 100)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(['x' => 1], $run->summary);
        $this->assertSame(120, $run->processed);
        Queue::assertPushed(RunIntegrationImportJob::class, 1);
    }

    public function testAFailingItemIsCountedAndTheRestContinue(): void
    {
        FakeImportRegistry::$items = [1, 2, 3];
        FakeImportRegistry::$failOn = [2];
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $run = $run->fresh();
        $this->assertSame(1, $run->failed);
        $this->assertSame(2, $run->created);
        $this->assertSame([['id' => '2', 'error' => 'cannot import 2']], $run->errors);
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
    }

    public function testAnEmptyListCompletesWithoutQueuing(): void
    {
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(0, $run->total);
        Queue::assertNotPushed(RunIntegrationImportJob::class);
    }

    public function testASingleRunImporterRunsWhole(): void
    {
        $run = $this->makeRun('whole');

        $this->job($run, 0, FakeSingleRunImporter::class)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(['ran' => true], $run->summary);
        Queue::assertNotPushed(RunIntegrationImportJob::class);
    }

    public function testFailedMarksTheRunFailed(): void
    {
        $run = $this->makeRun('chunky');

        $this->job($run)->failed(new RuntimeException('worker died'));

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::FAILED, $run->status);
        $this->assertSame('worker died', $run->error);
    }

    public function testAContinuationWhoseItemsExpiredFails(): void
    {
        $run = $this->makeRun('chunky');
        Cache::forget('integration-import-items:' . $run->getId());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lost its record list');

        $this->job($run, 100)->handle();
    }

    private function makeRun(string $entity): IntegrationImport
    {
        return IntegrationImport::create([
            'apps_id' => $this->row->apps_id,
            'companies_id' => $this->row->companies_id,
            'integrations_id' => $this->row->integrations_id,
            'integrations_companies_id' => $this->row->getId(),
            'users_id' => auth()->id(),
            'entity' => $entity,
            'status' => IntegrationImportStatusEnum::QUEUED,
            'options' => [],
            'queued_at' => Carbon::now(),
        ]);
    }

    private function job(IntegrationImport $run, int $offset = 0, string $importer = FakeChunkedImporter::class): RunIntegrationImportJob
    {
        $user = Users::getById(auth()->id());
        $row = $this->row;

        return new class ($run, $offset, new $importer($row, $user)) extends RunIntegrationImportJob {
            public function __construct(
                IntegrationImport $run,
                int $offset,
                private readonly IntegrationImporter $fake
            ) {
                parent::__construct($run, $offset);
            }

            protected function importer(): IntegrationImporter
            {
                return $this->fake;
            }
        };
    }
}
