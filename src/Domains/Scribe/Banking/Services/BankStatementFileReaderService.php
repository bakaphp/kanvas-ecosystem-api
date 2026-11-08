<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;

/**
 * The bytes of a stored statement file. Its own class so a test can bind a fake instead of standing up
 * the app's storage disk; production reads through FilesystemServices, which falls back to the
 * SSRF-guarded fetch for files outside the configured bucket.
 */
class BankStatementFileReaderService
{
    public function read(Filesystem $file): string
    {
        return FilesystemServices::readBytes($file);
    }
}
