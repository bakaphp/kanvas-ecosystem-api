<?php

declare(strict_types=1);

namespace Kanvas\Imports\RemoteFiles;

use Kanvas\Exceptions\ValidationException;
use Override;
use phpseclib4\Net\SFTP;
use RuntimeException;

/**
 * phpseclib 4 directly, not Flysystem: league/flysystem-sftp-v3 needs phpseclib ^3 and
 * laravel/socialite pins ^4. Never cache an instance on a static property — it holds an open,
 * authenticated socket that must not outlive the request or job under Octane.
 */
class SftpRemoteFileClient implements RemoteFileClient
{
    public const int CONNECT_TIMEOUT_SECONDS = 15;

    protected SFTP $sftp;

    public function __construct(
        string $host,
        int $port,
        string $username,
        string $password,
        ?string $root = null
    ) {
        $this->sftp = new SFTP($host, $port, self::CONNECT_TIMEOUT_SECONDS);

        if (! $this->sftp->login($username, $password)) {
            throw new ValidationException('Could not log in to the SFTP server');
        }

        if ($root !== null && $root !== '') {
            $this->sftp->chdir($root);
        }
    }

    #[Override]
    public function listFiles(): array
    {
        // Every phpseclib4 exception extends RuntimeException; it throws where v3 returned false.
        try {
            $entries = $this->sftp->nlist('.');
        } catch (RuntimeException) {
            return [];
        }

        $names = [];
        foreach ($entries as $entry) {
            $name = (string) $entry;
            if ($name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function read(string $remotePath): ?string
    {
        try {
            return $this->sftp->get($remotePath);
        } catch (RuntimeException) {
            return null;
        }
    }

    #[Override]
    public function downloadTo(string $remotePath, string $localPath): bool
    {
        try {
            $this->sftp->get($remotePath, $localPath);
        } catch (RuntimeException) {
            return false;
        }

        return is_file($localPath);
    }

    #[Override]
    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }
}
