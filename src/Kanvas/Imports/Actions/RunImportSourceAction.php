<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Kanvas\Filesystem\Actions\CreateFileSystemImportAction;
use Kanvas\Filesystem\DataTransferObject\FilesystemImport;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Imports\DataTransferObject\ImportRunResult;
use Kanvas\Imports\DataTransferObject\MergedImportFeed;
use Kanvas\Imports\Enums\ImportRunStatusEnum;
use Kanvas\Imports\Models\ImportSource;
use Kanvas\Imports\RemoteFiles\RemoteFileClient;
use Kanvas\Imports\RemoteFiles\RemoteFileClientFactory;
use Kanvas\Inventory\Channels\Actions\UnPublishAllVariantsAction;
use Kanvas\Inventory\Products\Actions\ImportProductFromFilesystemAction;
use Kanvas\Inventory\Products\Models\Products;
use Throwable;

/**
 * One run of one import source. Everything up to the FilesystemImports row is new; from there the
 * existing observer → mapper → importer pipeline takes over, exactly as for a manual CSV upload.
 *
 * Nothing is unpublished unless every required file arrived and the merged feed has rows: a missing
 * file must never read as "every car was sold".
 */
class RunImportSourceAction
{
    public function __construct(
        private readonly ImportSource $source,
        private readonly bool $dryRun = false,
        private readonly int $sampleSize = 5,
        private readonly RemoteFileClientFactory $clients = new RemoteFileClientFactory(),
        private readonly ?FilesystemServices $filesystemService = null,
    ) {
    }

    public function execute(): ImportRunResult
    {
        $workDir = sys_get_temp_dir() . '/import-source-' . $this->source->uuid . '-' . Str::random(8);
        File::ensureDirectoryExists($workDir);

        if (! $this->dryRun) {
            $this->record(ImportRunStatusEnum::RUNNING, 'Downloading files');
        }

        try {
            $result = $this->run($workDir);
        } catch (Throwable $e) {
            if (! $this->dryRun) {
                $this->record(ImportRunStatusEnum::FAILED, $e->getMessage());
            }

            throw $e;
        } finally {
            File::deleteDirectory($workDir);
        }

        if (! $this->dryRun) {
            $this->record($result->status, $result->message, $result->filesystemImport);
        }

        return $result;
    }

    private function run(string $workDir): ImportRunResult
    {
        $client = $this->clients->make($this->source->importConnection, $this->source->effectiveRoot());

        try {
            $downloads = $this->download($client, $workDir);
        } finally {
            $client->disconnect();
        }

        $files = array_map(
            fn (array $download) => [
                'pattern' => $download['pattern'],
                'matched' => $download['matched'],
                'downloaded' => $download['path'] !== null,
            ],
            $downloads
        );
        $downloaded = array_values(array_filter($downloads, fn (array $download) => $download['path'] !== null));

        $skipReason = $this->skipReason($downloads, $downloaded);
        if ($skipReason !== null) {
            return new ImportRunResult(ImportRunStatusEnum::SKIPPED, $skipReason, $files);
        }

        $feed = new MergeImportFilesAction(
            $downloaded,
            $this->source->filesystemMapper->mapping,
            $workDir . '/merged.csv'
        )->execute();

        if ($feed->rows === 0) {
            return new ImportRunResult(
                status: ImportRunStatusEnum::SKIPPED,
                message: 'The files had no rows to import',
                files: $files,
                skippedRows: $feed->skippedRows,
            );
        }

        if ($this->dryRun) {
            return new ImportRunResult(
                status: ImportRunStatusEnum::COMPLETED,
                message: 'Dry run: nothing was unpublished or imported',
                files: $files,
                rows: $feed->rows,
                skippedRows: $feed->skippedRows,
                sample: $this->sample($feed, $workDir),
            );
        }

        if ($this->source->unpublish_missing && $this->source->channel !== null) {
            new UnPublishAllVariantsAction($this->source->channel, $feed->skus)->execute();
        }

        return new ImportRunResult(
            status: ImportRunStatusEnum::COMPLETED,
            message: sprintf('%d rows from %d file(s) queued for import', $feed->rows, count($downloaded)),
            files: $files,
            rows: $feed->rows,
            skippedRows: $feed->skippedRows,
            filesystemImport: $this->createImport($feed),
        );
    }

    /**
     * Stops at the first required file that is missing or empty: nothing after it can be imported.
     *
     * @return list<array{pattern: string, matched: string|null, path: string|null, filter: array|null, required: bool}>
     */
    private function download(RemoteFileClient $client, string $workDir): array
    {
        $available = $client->listFiles();
        $downloads = [];

        foreach ($this->source->files as $index => $spec) {
            $matched = $this->match((string) $spec['pattern'], $available);
            $localPath = $workDir . '/' . $index . '.csv';
            $ok = $matched !== null
                && $client->downloadTo($matched, $localPath)
                && filesize($localPath) > 0;

            $downloads[] = [
                'pattern' => (string) $spec['pattern'],
                'matched' => $matched,
                'path' => $ok ? $localPath : null,
                'filter' => $spec['filter'] ?? null,
                'required' => (bool) ($spec['required'] ?? true),
            ];

            if (! $ok && ($spec['required'] ?? true)) {
                break;
            }
        }

        return $downloads;
    }

    private function skipReason(array $downloads, array $downloaded): ?string
    {
        foreach ($downloads as $download) {
            if ($download['required'] && $download['path'] === null) {
                return sprintf('Required file %s is %s', $download['pattern'], $download['matched'] === null ? 'missing' : 'empty');
            }
        }

        return $downloaded === [] ? 'None of the files were found' : null;
    }

    /**
     * @param list<string> $available
     */
    private function match(string $pattern, array $available): ?string
    {
        foreach ($available as $name) {
            if (fnmatch(strtolower($pattern), strtolower($name))) {
                return $name;
            }
        }

        return null;
    }

    private function createImport(MergedImportFeed $feed): FilesystemImports
    {
        $filesystemService = $this->filesystemService ?? new FilesystemServices($this->source->app, $this->source->company);
        $filesystem = $filesystemService->upload(
            new UploadedFile(
                $feed->path,
                Str::slug($this->source->name) . '-' . now()->format('Ymd-His') . '.csv',
                'text/csv',
                null,
                true,
            ),
            $this->source->user
        );

        return new CreateFileSystemImportAction(
            new FilesystemImport(
                app: $this->source->app,
                users: $this->source->user,
                companies: $this->source->company,
                regions: $this->source->region,
                companiesBranches: $this->source->branch,
                filesystem: $filesystem,
                filesystemMapper: $this->source->filesystemMapper,
                extra: $this->source->runExtra(),
            )
        )->execute();
    }

    /**
     * The first records as the importer will receive them. For products that is the real CSV → JSONL
     * transform, so the preview can't drift from what a real run imports.
     *
     * @return list<array<string, mixed>>
     */
    private function sample(MergedImportFeed $feed, string $workDir): array
    {
        $mapper = $this->source->filesystemMapper;
        if ($mapper->systemModule->model_name !== Products::class) {
            return [];
        }

        $import = new FilesystemImports();
        $import->uuid = 'dry-run-' . $this->source->uuid;
        $import->extra = $this->source->runExtra();
        $import->setRelation('filesystemMapper', $mapper);
        $import->setRelation('company', $this->source->company);
        $import->setRelation('app', $this->source->app);

        $jsonlPath = $workDir . '/sample.jsonl';
        new ImportProductFromFilesystemAction($import)->streamCsvFileToJsonlFile($feed->path, $jsonlPath);

        $sample = [];
        $handle = fopen($jsonlPath, 'r');
        while ($handle !== false && count($sample) < $this->sampleSize && ($line = fgets($handle)) !== false) {
            $sample[] = json_decode($line, true);
        }
        if ($handle !== false) {
            fclose($handle);
        }

        return $sample;
    }

    private function record(
        ImportRunStatusEnum $status,
        string $message,
        ?FilesystemImports $import = null
    ): void {
        if ($status === ImportRunStatusEnum::RUNNING) {
            $this->source->last_run_at = now();
        }

        // A skipped or failed run keeps pointing at the last import that did happen.
        if ($import !== null) {
            $this->source->last_filesystem_imports_id = $import->getId();
        }

        $this->source->last_status = $status;
        $this->source->last_message = mb_substr($message, 0, 1000);
        $this->source->save();
    }
}
