<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
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

    public function testAnItemWhoseIdCannotBeReadFailsAloneUnderItsPosition(): void
    {
        FakeImportRegistry::$items = [1, 2, 3];
        FakeImportRegistry::$failOn = [2];
        FakeImportRegistry::$unlabelable = [2];
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(1, $run->failed);
        $this->assertSame(2, $run->created);
        $this->assertSame([['id' => '#1', 'error' => 'cannot import 2']], $run->errors);
        $this->assertSame([1, 3], FakeImportRegistry::$imported);
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
        FakeImportRegistry::$items = range(1, 120);
        $run = $this->makeRun('chunky');
        $this->job($run)->handle();
        Cache::forget('integration-import-items:' . $run->getId() . ':1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lost its record list');

        $this->job($run->fresh(), 100)->handle();
    }

    public function testEachChunkReadsOnlyItsOwnKeyAndForgetsIt(): void
    {
        FakeImportRegistry::$items = range(1, 250);
        $run = $this->makeRun('chunky');
        $key = 'integration-import-items:' . $run->getId() . ':';

        $this->job($run)->handle();

        $this->assertFalse(Cache::has($key . '0'));
        $this->assertSame(range(101, 200), Cache::get($key . '1'));
        $this->assertSame(range(201, 250), Cache::get($key . '2'));

        Cache::put($key . '2', [9001, 9002], now()->addHour());
        $this->job($run->fresh(), 100)->handle();

        $this->assertFalse(Cache::has($key . '1'));
        $this->assertTrue(Cache::has($key . '2'));

        $this->job($run->fresh(), 200)->handle();

        $this->assertFalse(Cache::has($key . '2'));
        $this->assertSame([...range(1, 200), 9001, 9002], FakeImportRegistry::$imported);
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->fresh()->status);
    }

    public function testCompletedRunsOnceTheRunIsFinished(): void
    {
        FakeImportRegistry::$items = [1];
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, FakeImportRegistry::$statusAtCompletion);
    }

    public function testAFollowUpFailureIsReportedAndTheRunStaysCompleted(): void
    {
        Exceptions::fake();
        FakeImportRegistry::$items = [1, 2];
        FakeImportRegistry::$completedThrows = 'stock lock exploded';
        $run = $this->makeRun('chunky');

        $this->job($run)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(['follow_up_error' => 'stock lock exploded'], $run->summary);
        $this->assertSame(2, $run->created);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'stock lock exploded');
    }

    public function testAContinuationStopsWhenTheIntegrationWasDisconnected(): void
    {
        FakeImportRegistry::$items = range(1, 120);
        $run = $this->makeRun('chunky');
        $this->job($run)->handle();
        $this->row->is_active = false;
        $this->row->save();

        $this->job($run->fresh(), 100)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::FAILED, $run->status);
        $this->assertSame('The integration was disconnected during the import.', $run->error);
        $this->assertSame(100, $run->processed);
        $this->assertFalse(Cache::has('integration-import-items:' . $run->getId() . ':1'));
        Queue::assertPushed(RunIntegrationImportJob::class, 1);
    }

    public function testTheRealImporterIsResolvedAndTheRunsAppIsBound(): void
    {
        FakeImportRegistry::$items = [1, 2];
        $run = $this->makeRun('chunky');
        $otherApp = Apps::query()->where('id', '!=', $run->apps_id)->first();

        if ($otherApp !== null) {
            app()->instance(Apps::class, $otherApp);
        }

        new RunIntegrationImportJob($run)->handle();

        $run = $run->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $run->status);
        $this->assertSame(2, $run->created);
        $this->assertSame([1, 2], FakeImportRegistry::$imported);
        $this->assertSame($run->apps_id, app(Apps::class)->getId());
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
