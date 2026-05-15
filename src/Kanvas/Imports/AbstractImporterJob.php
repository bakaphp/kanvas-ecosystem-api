<?php

declare(strict_types=1);

namespace Kanvas\Imports;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Generator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Regions\Models\Regions;
use RuntimeException;

abstract class AbstractImporterJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    /**
    * The number of seconds after which the job's unique lock will be released.
    *
    * @var int
    */
    public $uniqueFor = 0;

    public function __construct(
        public string $jobUuid,
        public array $importer,
        public CompaniesBranches $branch,
        public UserInterface $user,
        public Regions $region,
        public AppInterface $app,
        public ?FilesystemImports $filesystemImport = null,
        public bool $runWorkflow = true
    ) {
        $minuteDelay = (int)($app->get('delay_minute_job') ?? 0);
        $queue = $this->onQueue('imports');
        if ($minuteDelay) {
            $queue->delay(now()->addMinutes($minuteDelay));
        }

        $minuteUniqueFor = (int)($app->get('unique_for_minute_job') ?? 1);
        if (App::environment('production')) {
            $this->uniqueFor = $minuteUniqueFor * 60;
        }
    }

    /**
     * Get the unique ID for the job.
     *
     * Prefer the FilesystemImports id when the payload was spooled to a file —
     * it's a stable identifier with zero memory cost. Fall back to a streaming
     * md5 of the in-memory array for legacy callers (no second-string copy,
     * unlike json_encode + md5 which used to OOM on large payloads).
     */
    public function uniqueId(): string
    {
        $key = $this->filesystemImport && $this->filesystemImport->getId()
            ? 'fi-' . (string) $this->filesystemImport->getId()
            : self::hashImporterStreaming($this->importer);

        return $this->app->getId() . $this->branch->getId() . $this->region->getId() . $key;
    }

    public function middleware(): array
    {
        if (! $this->uniqueFor) {
            return [];
        }

        return [
            new WithoutOverlapping($this->uniqueId())->expireAfter($this->uniqueFor),
        ];
    }

    /**
     * handle.
     *
     * @return void
     */
    abstract public function handle();

    /**
     * Yield importer rows one at a time from either the spooled JSONL file
     * or the in-memory array. Concrete jobs iterate this generator instead
     * of `$this->importer` directly so they don't care which source is in use.
     *
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function iterateImporterRows(): Generator
    {
        if ($this->hasStreamableFile()) {
            yield from $this->streamJsonlRows();

            return;
        }

        /** @var array<int, array<array-key, mixed>> $importer */
        $importer = $this->importer;
        yield from $importer;
    }

    protected function hasStreamableFile(): bool
    {
        return $this->getStreamableFilesystem() !== null;
    }

    /**
     * Resolve the file the job should stream from.
     *
     * Two paths can populate this:
     *  - PR 2 direct spool: the resolver wrote a `.jsonl` straight to the
     *    primary `filesystem` reference (no mapper, no transformation).
     *  - PR 3 transform path: a CSV-with-mapper upload was streamed through
     *    `ImportProductFromFilesystemAction` which produced a JSONL and
     *    attached its filesystem id under `extra.streamable_filesystem_id`,
     *    keeping the original CSV as the primary file for audit.
     *
     * The transform-path lookup wins when present so the worker streams
     * the already-mapped JSONL instead of re-parsing the source CSV.
     */
    protected function getStreamableFilesystem(): ?Filesystem
    {
        if (! $this->filesystemImport) {
            return null;
        }

        $extra = $this->filesystemImport->extra;
        if (is_array($extra) && isset($extra['streamable_filesystem_id'])) {
            $streamable = Filesystem::find((int) $extra['streamable_filesystem_id']);
            if ($streamable && $this->isJsonlFilesystem($streamable)) {
                return $streamable;
            }
        }

        $filesystem = $this->filesystemImport->filesystem;
        if ($filesystem && $this->isJsonlFilesystem($filesystem)) {
            return $filesystem;
        }

        return null;
    }

    private function isJsonlFilesystem(Filesystem $filesystem): bool
    {
        $name = strtolower($filesystem->name !== '' ? $filesystem->name : $filesystem->path);

        return str_ends_with($name, '.jsonl');
    }

    /**
     * @return Generator<int, array<array-key, mixed>>
     */
    protected function streamJsonlRows(): Generator
    {
        $filesystem = $this->getStreamableFilesystem();
        if (! $filesystem) {
            return;
        }

        /** @var Apps $app */
        $app = $this->app;
        $service = new FilesystemServices($app);
        $localPath = $service->getFileLocalPath($filesystem);

        $handle = fopen($localPath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open importer payload at ' . $localPath);
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (! is_array($row)) {
                    continue;
                }

                yield $row;
            }
        } finally {
            fclose($handle);
            if (file_exists($localPath)) {
                @unlink($localPath);
            }
        }
    }

    /**
     * Streaming md5 of the importer array — walks values without materializing
     * a json_encode string copy, so peak memory stays at one PHP-array size
     * instead of (array + giant string).
     */
    protected static function hashImporterStreaming(array $importer): string
    {
        $ctx = hash_init('md5');
        array_walk_recursive($importer, function (mixed $value, int|string $key) use ($ctx): void {
            hash_update($ctx, $key . '=' . (is_scalar($value) ? (string) $value : ''));
        });

        return hash_final($ctx);
    }

    protected function startFilesystemMapperImport(): void
    {
        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'status' => 'processing',
            ]);
        }
    }

    protected function finishFilesystemMapperImport(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        array $errors,
        array $importedEntities = []
    ): void {
        if ($this->filesystemImport) {
            $this->filesystemImport->update([
                'results' => [
                    'summary' => [
                        'total_items' => $totalItems,
                        'total_process_successfully' => $totalProcessSuccessfully,
                        'total_process_failed' => $totalProcessFailed,
                    ],
                    'details' => $importedEntities
                ],
                'exception' => $errors,
                'status' => 'completed',
                'finished_at' => now(),
            ]);
        }
    }

    abstract protected function notificationStatus(
        int $totalItems,
        int $totalProcessSuccessfully,
        int $totalProcessFailed,
        int $created,
        int $updated,
        array $errors,
        Companies $company
    ): void ;
}
