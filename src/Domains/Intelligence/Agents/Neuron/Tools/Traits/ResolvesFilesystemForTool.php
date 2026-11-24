<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Http\SafeUrlFetcher;
use Baka\Support\Str;
use Baka\Support\TempFile;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use RuntimeException;
use Throwable;

/**
 * Resolve an LLM-supplied filesystem_id, and read the file through a disposable local copy.
 *
 * Scoped by company as well as app: the id arrives from the model, so an app hosting several
 * companies would otherwise read another company's document off a hallucinated or injected id.
 *
 * Requires HasKanvasContext ($this->app / $this->company) on the same tool.
 */
trait ResolvesFilesystemForTool
{
    protected function findTenantFile(int $filesystemId): ?Filesystem
    {
        return Filesystem::query()
            ->where('id', $filesystemId)
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();
    }

    /**
     * The file a person handed the agent (filesystem_id) or a link to one, stored in Kanvas so the
     * document it ends up on can point at it. The URL goes through FilesystemServices, which applies the
     * SSRF guard before downloading. Returns an error payload when neither resolves.
     *
     * @return Filesystem|array{reason: string, message: string}
     */
    protected function resolveOrUploadTenantFile(?int $filesystemId, ?string $fileUrl): Filesystem|array
    {
        if ($filesystemId !== null) {
            return $this->findTenantFile($filesystemId) ?? [
                'reason' => 'file_not_found',
                'message' => "No file with filesystem_id {$filesystemId} for this company.",
            ];
        }

        $url = Str::trimToNull($fileUrl);

        if ($url === null) {
            return [
                'reason' => 'no_file_given',
                'message' => 'Pass either filesystem_id (a file already in Kanvas, from an attachment marker) or file_url.',
            ];
        }

        try {
            return new FilesystemServices($this->app, $this->company)->uploadFileFromUrl($url, $this->user);
        } catch (Throwable $e) {
            return [
                'reason' => 'file_download_failed',
                'message' => 'Could not download the file from that URL: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Downloads the file to a temp path, hands it to $read, and always deletes the copy. Parsers
     * here take a path, not bytes. Throws whatever the fetch or $read throws — callers turn that
     * into their own tool-shaped error payload.
     *
     * @template T
     * @param callable(string): T $read
     * @return T
     */
    protected function withDownloadedFile(
        Filesystem $file,
        string $prefix,
        string $extension,
        callable $read
    ): mixed {
        $reservedPath = tempnam(sys_get_temp_dir(), $prefix);

        if ($reservedPath === false) {
            throw new RuntimeException('Could not create a temporary download file.');
        }

        $tempPath = $reservedPath . '.' . $extension;

        try {
            file_put_contents($tempPath, SafeUrlFetcher::fetch((string) $file->url));

            return $read($tempPath);
        } finally {
            TempFile::delete($reservedPath, $tempPath);
        }
    }
}
