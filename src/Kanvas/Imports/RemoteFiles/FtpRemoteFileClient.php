<?php

declare(strict_types=1);

namespace Kanvas\Imports\RemoteFiles;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Override;
use Throwable;

class FtpRemoteFileClient implements RemoteFileClient
{
    public const int CONNECT_TIMEOUT_SECONDS = 30;

    private Filesystem $disk;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        ?string $root = null,
        bool $passive = true,
    ) {
        $this->disk = Storage::build([
            'driver' => 'ftp',
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'root' => $root ?? '',
            'passive' => $passive,
            // A passive-mode reply names the IP for the data connection; a hostile server could
            // point it at an internal address. Reuse the control connection's (already vetted) IP.
            'ignorePassiveAddress' => true,
            'timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'throw' => true,
        ]);
    }

    #[Override]
    public function listFiles(): array
    {
        return array_values(array_map('basename', $this->disk->files()));
    }

    #[Override]
    public function downloadTo(string $remotePath, string $localPath): bool
    {
        try {
            $stream = $this->disk->readStream($remotePath);
        } catch (Throwable) {
            return false;
        }

        if (! is_resource($stream)) {
            return false;
        }

        $out = fopen($localPath, 'w');
        if ($out === false) {
            fclose($stream);

            return false;
        }

        // A copy that dies mid-transfer must not report success: the caller would merge a truncated
        // feed and unpublish every row that never arrived.
        $copied = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        return $copied !== false;
    }

    #[Override]
    public function disconnect(): void
    {
    }
}
