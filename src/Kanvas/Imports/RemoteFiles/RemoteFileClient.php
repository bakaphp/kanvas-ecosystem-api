<?php

declare(strict_types=1);

namespace Kanvas\Imports\RemoteFiles;

interface RemoteFileClient
{
    /**
     * @return list<string> file names in the working directory
     */
    public function listFiles(): array;

    /**
     * Streams a remote file to a local path. False when the file is missing or unreadable.
     */
    public function downloadTo(string $remotePath, string $localPath): bool;

    public function disconnect(): void;
}
