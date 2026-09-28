<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Imports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Imports\Actions\RunImportSourceAction;
use Kanvas\Imports\DataTransferObject\ImportRunResult;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Kanvas\Imports\Models\ImportSource;
use RuntimeException;
use Tests\Ecosystem\Integration\Imports\Concerns\CreatesImportSources;
use Tests\Ecosystem\Integration\Imports\Fakes\FakeRemoteFileClient;
use Tests\Ecosystem\Integration\Imports\Fakes\FakeRemoteFileClientFactory;
use Tests\TestCase;

final class RunImportSourceActionTest extends TestCase
{
    use CreatesImportSources;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'inventory'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function testMergesTheFilesAndQueuesOneImportWithTheSourceDestination(): void
    {
        $source = $this->makeSource([
            ['pattern' => 'MP10425.csv'],
            ['pattern' => 'mp22154.CSV', 'filter' => ['column' => 'New/Used', 'in' => ['Used', 'U']]],
        ]);
        $client = new FakeRemoteFileClient([
            'MP10425.csv' => $this->dealerCsv(
                ['VIN-A', 'S1', 'New', '2026', 'Cadillac', 'XT6', 'Sport', '62000', '59900', ''],
                ['VIN-B', 'S2', 'Used', '2020', 'Honda', 'Civic', '', '', '18500', ''],
            ),
            'MP22154.csv' => $this->dealerCsv(
                ['VIN-B', 'S9', 'Used', '2020', 'Honda', 'Civic', '', '', '99999', ''],
                ['VIN-C', 'S3', 'Used', '2019', 'Ford', 'F-150', '', '', '25000', ''],
                ['VIN-D', 'S4', 'New', '2026', 'Ford', 'Bronco', '', '48000', '47000', ''],
            ),
        ]);

        $result = $this->runWith($source, $client);

        $this->assertSame(ImportRunStatusEnum::COMPLETED, $result->status, $result->message);
        $this->assertSame(3, $result->rows, 'VIN-B from the second file and the New VIN-D are dropped');
        $this->assertTrue($client->disconnected);

        $import = FilesystemImports::find($result->filesystemImport->getId());
        $this->assertSame($source->filesystem_mapper_id, (int) $import->filesystem_mapper_id);
        $this->assertSame($source->regions_id, (int) $import->regions_id);
        $this->assertEquals(
            ['warehouse_id' => $source->warehouses_id, 'channels_id' => $source->channels_id, 'deleteAfterUse' => false],
            $import->extra
        );

        $source->refresh();
        $this->assertSame(ImportRunStatusEnum::COMPLETED, $source->last_status);
        $this->assertSame($import->getId(), (int) $source->last_filesystem_imports_id);
        $this->assertNotNull($source->last_run_at);
    }

    public function testAMissingRequiredFileSkipsTheRunAndCreatesNoImport(): void
    {
        $source = $this->makeSource([
            ['pattern' => 'MP10425.csv'],
            ['pattern' => 'MP24456.csv'],
        ]);
        $client = new FakeRemoteFileClient([
            'MP10425.csv' => $this->dealerCsv(['VIN-A', 'S1', 'New', '2026', 'Cadillac', 'XT6', '', '62000', '', '']),
        ]);
        $importsBefore = FilesystemImports::count();

        $result = $this->runWith($source, $client);

        $this->assertSame(ImportRunStatusEnum::SKIPPED, $result->status);
        $this->assertSame('Required file MP24456.csv is missing', $result->message);
        $this->assertSame($importsBefore, FilesystemImports::count());
        $this->assertSame(ImportRunStatusEnum::SKIPPED, $source->refresh()->last_status);
    }

    public function testAnOptionalMissingFileDoesNotStopTheRun(): void
    {
        $source = $this->makeSource([
            ['pattern' => 'MP10425.csv'],
            ['pattern' => 'MP24456.csv', 'required' => false],
        ]);
        $client = new FakeRemoteFileClient([
            'MP10425.csv' => $this->dealerCsv(['VIN-A', 'S1', 'New', '2026', 'Cadillac', 'XT6', '', '62000', '', '']),
        ]);

        $result = $this->runWith($source, $client);

        $this->assertSame(ImportRunStatusEnum::COMPLETED, $result->status, $result->message);
        $this->assertSame(1, $result->rows);
        $this->assertFalse($result->files[1]['downloaded']);
    }

    public function testAFileWithOnlyAHeaderSkipsTheRun(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);

        $result = $this->runWith($source, new FakeRemoteFileClient(['MP10425.csv' => $this->dealerCsv()]));

        $this->assertSame(ImportRunStatusEnum::SKIPPED, $result->status);
        $this->assertSame('The files had no rows to import', $result->message);
    }

    public function testADryRunReturnsTheMappedProductsAndWritesNothing(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);
        $client = new FakeRemoteFileClient([
            'MP10425.csv' => $this->dealerCsv(['VIN-A', 'S1', 'New', '2026', 'Cadillac', 'XT6', 'Sport', '62000', '59900', '']),
        ]);
        $importsBefore = FilesystemImports::count();

        $result = new RunImportSourceAction(
            source: $source,
            dryRun: true,
            clients: new FakeRemoteFileClientFactory($client),
        )->execute();

        $this->assertSame(ImportRunStatusEnum::COMPLETED, $result->status);
        $this->assertSame('Cadillac XT6 2026 Sport', $result->sample[0]['name']);
        $this->assertSame($source->channels_id, $result->sample[0]['variants'][0]['channels'][0]['channels_id']);
        $this->assertSame($importsBefore, FilesystemImports::count());
        $this->assertNull($source->refresh()->last_status);
    }

    public function testAConnectionErrorIsRecordedAsFailedAndRethrown(): void
    {
        $source = $this->makeSource([['pattern' => 'MP10425.csv']]);

        try {
            $this->runWith($source, new FakeRemoteFileClient([], failOnList: true));
            $this->fail('Expected the connection error to be rethrown');
        } catch (RuntimeException $e) {
            $this->assertSame('Connection reset by peer', $e->getMessage());
        }

        $source->refresh();
        $this->assertSame(ImportRunStatusEnum::FAILED, $source->last_status);
        $this->assertSame('Connection reset by peer', $source->last_message);
    }

    private function runWith(ImportSource $source, FakeRemoteFileClient $client): ImportRunResult
    {
        return new RunImportSourceAction(
            source: $source,
            clients: new FakeRemoteFileClientFactory($client),
            filesystemService: $this->fakeUploads(),
        )->execute();
    }
}
