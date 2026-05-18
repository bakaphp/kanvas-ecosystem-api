<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use Generator;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Imports\AbstractImporterJob;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Tests\TestCaseUnit;

class AbstractImporterJobTest extends TestCaseUnit
{
    private function makeJob(array $importer = [], ?FilesystemImports $filesystemImport = null): AbstractImporterJob
    {
        $branch = new CompaniesBranches();
        $branch->id = 1;

        $user = new Users();
        $user->id = 1;

        $region = new Regions();
        $region->id = 1;

        $app = new Apps();
        $app->id = 1;

        return new class ('test-uuid', $importer, $branch, $user, $region, $app, $filesystemImport) extends AbstractImporterJob {
            public function handle()
            {
            }

            protected function notificationStatus(
                int $totalItems,
                int $totalProcessSuccessfully,
                int $totalProcessFailed,
                int $created,
                int $updated,
                array $errors,
                Companies $company,
            ): void {
            }

            public static function exposedHashImporterStreaming(array $importer): string
            {
                return self::hashImporterStreaming($importer);
            }

            public function exposedHasStreamableFile(): bool
            {
                return $this->hasStreamableFile();
            }

            public function exposedIterateImporterRows(): Generator
            {
                yield from $this->iterateImporterRows();
            }
        };
    }

    public function testStreamingHashIsDeterministic(): void
    {
        $payload = [['name' => 'foo', 'sku' => 'X-1'], ['name' => 'bar', 'sku' => 'X-2']];
        $job = $this->makeJob();

        $this->assertSame(
            $job::exposedHashImporterStreaming($payload),
            $job::exposedHashImporterStreaming($payload),
            'Same payload must produce the same hash on every call',
        );
    }

    public function testStreamingHashDiscriminatesPayloads(): void
    {
        $job = $this->makeJob();

        $this->assertNotSame(
            $job::exposedHashImporterStreaming([['name' => 'foo']]),
            $job::exposedHashImporterStreaming([['name' => 'bar']]),
            'Different payloads must produce different hashes',
        );
    }

    public function testStreamingHashWalksNestedArrays(): void
    {
        $job = $this->makeJob();

        $a = $job::exposedHashImporterStreaming([
            ['name' => 'p1', 'attrs' => [['k' => 'color', 'v' => 'red']]],
        ]);

        $b = $job::exposedHashImporterStreaming([
            ['name' => 'p1', 'attrs' => [['k' => 'color', 'v' => 'blue']]],
        ]);

        $this->assertNotSame($a, $b, 'A change deep in nested arrays must produce a different hash');
    }

    public function testStreamingHashReturnsMd5LikeString(): void
    {
        $hash = $this->makeJob()::exposedHashImporterStreaming([['name' => 'foo']]);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $hash, 'Hash must be a 32-char md5 hex string');
    }

    public function testUniqueIdUsesFilesystemImportIdWhenAvailable(): void
    {
        $import = new FilesystemImports();
        $import->id = 42;

        $job = $this->makeJob([['name' => 'foo']], $import);
        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString('fi-42', $uniqueId);
        $this->assertStringEndsWith('fi-42', $uniqueId);
    }

    public function testUniqueIdFallsBackToStreamingHashWhenNoFilesystemImport(): void
    {
        $importer = [['name' => 'foo']];
        $job = $this->makeJob($importer);
        $expectedHash = $job::exposedHashImporterStreaming($importer);

        $uniqueId = $job->uniqueId();

        $this->assertStringContainsString($expectedHash, $uniqueId);
        $this->assertStringNotContainsString('fi-', $uniqueId);
    }

    public function testUniqueIdDoesNotMaterializeJsonStringForLargePayload(): void
    {
        // Build a payload large enough that md5(json_encode(...)) would have
        // a noticeable peak-memory cost vs. streaming. Not asserting exact memory,
        // just that the call completes and returns a valid hash without OOM.
        $payload = [];
        for ($i = 0; $i < 5000; $i++) {
            $payload[] = [
                'name' => 'Product ' . $i,
                'sku' => 'SKU-' . $i,
                'description' => str_repeat('lorem ipsum ', 50),
            ];
        }

        $job = $this->makeJob($payload);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $job::exposedHashImporterStreaming($payload));
    }

    public function testHasStreamableFileReturnsFalseWhenNoFilesystemImport(): void
    {
        $this->assertFalse($this->makeJob()->exposedHasStreamableFile());
    }

    public function testHasStreamableFileReturnsFalseWhenFilesystemRelationMissing(): void
    {
        $import = new FilesystemImports();
        $import->id = 1;
        // no filesystem_id set, no filesystem relation set

        $job = $this->makeJob([], $import);

        $this->assertFalse($job->exposedHasStreamableFile());
    }

    public function testHasStreamableFileReturnsFalseForCsvFile(): void
    {
        $filesystem = new Filesystem(['name' => 'products.csv']);
        $import = $this->makeImport($filesystem);

        $job = $this->makeJob([], $import);

        $this->assertFalse(
            $job->exposedHasStreamableFile(),
            'CSV files must use the legacy array path, not JSONL streaming',
        );
    }

    public function testHasStreamableFileReturnsTrueForJsonlFile(): void
    {
        $filesystem = new Filesystem(['name' => 'importer-12345.jsonl']);
        $import = $this->makeImport($filesystem);

        $job = $this->makeJob([], $import);

        $this->assertTrue($job->exposedHasStreamableFile());
    }

    public function testHasStreamableFileFallsBackToPrimaryWhenExtraStreamableIdIsMissingFile(): void
    {
        // CSV upload with mapper that ALSO has extra.streamable_filesystem_id set,
        // but the referenced Filesystem row no longer exists. Should gracefully
        // fall back to the primary filesystem (which is CSV → returns false here).
        $filesystem = new Filesystem(['name' => 'products.csv']);
        $import = $this->makeImport($filesystem);
        $import->extra = ['streamable_filesystem_id' => 999999999];

        $job = $this->makeJob([], $import);

        $this->assertFalse(
            $job->exposedHasStreamableFile(),
            'A missing transformed file should fall back to primary, not crash',
        );
    }

    public function testHasStreamableFileIgnoresExtraWithoutStreamableIdKey(): void
    {
        $filesystem = new Filesystem(['name' => 'products.jsonl']);
        $import = $this->makeImport($filesystem);
        $import->extra = ['unrelated' => 'foo']; // extra is set but doesn't have our key

        $job = $this->makeJob([], $import);

        $this->assertTrue(
            $job->exposedHasStreamableFile(),
            'Unrelated extra keys must not interfere with primary file resolution',
        );
    }

    public function testHasStreamableFileIsCaseInsensitiveForJsonlExtension(): void
    {
        $filesystem = new Filesystem(['name' => 'Importer-Foo.JSONL']);
        $import = $this->makeImport($filesystem);

        $job = $this->makeJob([], $import);

        $this->assertTrue($job->exposedHasStreamableFile());
    }

    public function testIterateImporterRowsYieldsArrayContentsInLegacyMode(): void
    {
        $importer = [
            ['name' => 'p1'],
            ['name' => 'p2'],
            ['name' => 'p3'],
        ];

        $job = $this->makeJob($importer);
        $rows = iterator_to_array($job->exposedIterateImporterRows(), false);

        $this->assertSame($importer, $rows);
    }

    public function testIterateImporterRowsYieldsNothingForEmptyArrayLegacyMode(): void
    {
        $job = $this->makeJob([]);
        $rows = iterator_to_array($job->exposedIterateImporterRows(), false);

        $this->assertSame([], $rows);
    }

    private function makeImport(Filesystem $filesystem): FilesystemImports
    {
        $import = new FilesystemImports();
        $import->id = 1;
        $import->filesystem_id = 1;
        $import->setRelation('filesystem', $filesystem);

        return $import;
    }
}
