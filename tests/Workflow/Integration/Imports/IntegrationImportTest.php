<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChunkResult;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

final class IntegrationImportTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected $connectionsToTransact = [null, 'ecosystem', 'workflow'];

    private Companies $company;
    private Integrations $integration;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->freshCompany();
        $this->integration = Integrations::create([
            'apps_id' => app(Apps::class)->getId(),
            'name' => uniqid('import_test_'),
            'handler' => 'none',
            'type' => 'key',
        ]);
    }

    public function testLatestForIsSharedByRowsOfTheSameIntegration(): void
    {
        $rowA = $this->integrationCompany(1);
        $rowB = $this->integrationCompany(2);
        $run = $this->makeRun($rowA, 'products');

        $this->assertSame($run->getId(), IntegrationImport::latestFor($rowB, 'products')?->getId());
        $this->assertNull(IntegrationImport::latestFor($rowB, 'customers'));
    }

    public function testInProgressBlocksUntilStale(): void
    {
        $row = $this->integrationCompany(1);
        $this->makeRun($row, 'products');

        $this->assertNotNull(IntegrationImport::inProgressFor($row, 'products'));

        Carbon::setTestNow(Carbon::now()->addHours(IntegrationImport::STALE_AFTER_HOURS)->addMinute());

        try {
            $this->assertNull(IntegrationImport::inProgressFor($row, 'products'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testAdvanceSumsChunksAndCapsErrorsAtFifty(): void
    {
        $run = $this->makeRun($this->integrationCompany(1), 'products');

        foreach ([0, 1] as $chunkNumber) {
            $chunk = new ImportChunkResult();
            for ($i = 0; $i < 30; $i++) {
                $chunk->fail("item-{$chunkNumber}-{$i}", 'boom');
            }
            $run->advance($chunk);
        }

        $run = $run->fresh();
        $this->assertSame(60, $run->failed);
        $this->assertSame(60, $run->processed);
        $this->assertCount(IntegrationImport::MAX_ERRORS, $run->errors);
    }

    public function testAdvanceCountsEveryOutcome(): void
    {
        $run = $this->makeRun($this->integrationCompany(1), 'products');
        $chunk = new ImportChunkResult();
        $chunk->add(ImportOutcomeEnum::CREATED);
        $chunk->add(ImportOutcomeEnum::UPDATED);
        $chunk->add(ImportOutcomeEnum::SKIPPED);
        $run->advance($chunk);

        $run = $run->fresh();
        $this->assertSame([1, 1, 1, 0, 3], [$run->created, $run->updated, $run->skipped, $run->failed, $run->processed]);
    }

    public function testCompleteAndFailSetTheirTimestamps(): void
    {
        $row = $this->integrationCompany(1);

        $completed = $this->makeRun($row, 'products');
        $completed->markRunning(10);
        $this->assertNotNull($completed->fresh()->started_at);
        $this->assertSame(10, $completed->fresh()->total);

        $completed->complete(['stock' => 'queued']);
        $completed = $completed->fresh();
        $this->assertSame(IntegrationImportStatusEnum::COMPLETED, $completed->status);
        $this->assertNotNull($completed->finished_at);
        $this->assertSame(['stock' => 'queued'], $completed->summary);

        $failed = $this->makeRun($row, 'customers');
        $failed->fail('lost its record list');
        $failed = $failed->fresh();
        $this->assertSame(IntegrationImportStatusEnum::FAILED, $failed->status);
        $this->assertSame('lost its record list', $failed->error);
        $this->assertNotNull($failed->finished_at);
        $this->assertNull(IntegrationImport::inProgressFor($row, 'customers'));
    }

    private function integrationCompany(int $regionId): IntegrationsCompany
    {
        return IntegrationsCompany::create([
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integration->getId(),
            'region_id' => $regionId,
            'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
        ]);
    }

    private function makeRun(IntegrationsCompany $row, string $entity): IntegrationImport
    {
        return IntegrationImport::create([
            'apps_id' => $row->apps_id,
            'companies_id' => $row->companies_id,
            'integrations_id' => $row->integrations_id,
            'integrations_companies_id' => $row->getId(),
            'users_id' => auth()->id(),
            'entity' => $entity,
            'status' => IntegrationImportStatusEnum::QUEUED,
            'queued_at' => Carbon::now(),
        ]);
    }
}
