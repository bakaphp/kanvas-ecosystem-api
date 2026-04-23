<?php

declare(strict_types=1);

namespace Kanvas\Imports\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Models\FilesystemImports;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use RuntimeException;
use Throwable;

/**
 * Spools an inline importer array to a JSONL file on the Kanvas filesystem
 * and creates a `FilesystemImports` record pointing to it.
 *
 * The resulting `FilesystemImports` is dispatched to a downstream importer
 * job (`ProductImporterJob`, `CustomerImporterJob`, …) so the job streams
 * the file row-by-row instead of carrying the whole array through Redis.
 */
class SpoolImporterPayloadAction
{
    public function __construct(
        protected array $importer,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected CompaniesBranches $branch,
        protected Regions $region,
        protected ?FilesystemServices $filesystemService = null,
    ) {
    }

    public function execute(): FilesystemImports
    {
        $tempPath = $this->writeJsonlToTempFile();

        try {
            $filesystem = $this->uploadAsFilesystem($tempPath);
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }

        return $this->createFilesystemImport($filesystem);
    }

    /**
     * Write each row of the importer array as a single JSON line to a temp
     * file. We never `json_encode($this->importer)` as one big string — that's
     * the OOM trap we're escaping.
     */
    private function writeJsonlToTempFile(): string
    {
        $tempPath = sys_get_temp_dir() . '/importer-' . Str::uuid()->toString() . '.jsonl';
        self::writeJsonl($this->importer, $tempPath);

        return $tempPath;
    }

    /**
     * Stream-write rows to a JSONL file. Public + static so the resolver,
     * other spool actions, and unit tests can reuse the same writer without
     * spinning up the full action. Each row is encoded individually — never
     * the full array as one giant string — so peak memory stays at one row.
     */
    public static function writeJsonl(array $rows, string $path): void
    {
        // Suppress the warning so the false-return check works under Laravel's
        // HandleExceptions (which would otherwise rethrow it as ErrorException).
        $handle = @fopen($path, 'w');
        if ($handle === false) {
            throw new RuntimeException('Could not open file for JSONL spool: ' . $path);
        }

        try {
            foreach ($rows as $row) {
                $line = json_encode($row, JSON_THROW_ON_ERROR);
                fwrite($handle, $line . "\n");
            }
        } catch (Throwable $e) {
            fclose($handle);
            if (file_exists($path)) {
                @unlink($path);
            }

            throw $e;
        }

        fclose($handle);
    }

    private function uploadAsFilesystem(string $tempPath): Filesystem
    {
        /** @var Apps $app */
        $app = $this->app;
        /** @var Users $user */
        $user = $this->user;

        $service = $this->filesystemService ?? new FilesystemServices($app, $this->company);
        $uploadedFile = new UploadedFile(
            $tempPath,
            'importer-' . Str::uuid()->toString() . '.jsonl',
            'application/x-ndjson',
            null,
            true,
        );

        return $service->upload($uploadedFile, $user);
    }

    private function createFilesystemImport(Filesystem $filesystem): FilesystemImports
    {
        $import = new FilesystemImports();
        $import->apps_id = $this->app->getId();
        $import->users_id = $this->user->getId();
        $import->companies_id = $this->company->getId();
        $import->companies_branches_id = $this->branch->getId();
        $import->regions_id = $this->region->getId();
        $import->filesystem_id = $filesystem->getId();
        $import->filesystem_mapper_id = null;
        $import->status = 'pending';
        $import->is_deleted = 0;
        $import->saveOrFail();

        return $import;
    }
}
