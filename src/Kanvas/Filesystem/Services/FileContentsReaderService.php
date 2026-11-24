<?php

declare(strict_types=1);

namespace Kanvas\Filesystem\Services;

use Kanvas\Filesystem\Models\Filesystem;

/**
 * The bytes of a stored file. Its own class so a test can bind a fake instead of standing up the app's
 * storage disk; production reads through FilesystemServices, which falls back to the SSRF-guarded fetch
 * for files outside the configured bucket.
 */
class FileContentsReaderService
{
    public function read(Filesystem $file): string
    {
        return FilesystemServices::readBytes($file);
    }
}
