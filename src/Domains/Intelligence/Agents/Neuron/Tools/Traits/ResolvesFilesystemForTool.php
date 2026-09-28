<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Filesystem\Models\Filesystem;

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
        $tempPath = tempnam(sys_get_temp_dir(), $prefix) . '.' . $extension;

        try {
            file_put_contents($tempPath, SafeUrlFetcher::fetch((string) $file->url));

            return $read($tempPath);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}
