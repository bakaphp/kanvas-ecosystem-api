<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Services;

use Kanvas\Exceptions\ValidationException;
use phpseclib4\Net\SFTP;
use RuntimeException;

/**
 * Thin SFTP client used to pull a single inventory CSV per request.
 *
 * Never cache instances of this class on a static property — the underlying
 * SFTP socket holds credentials and a remote handle that must not survive
 * across requests under Octane. Build, login, read, throw away.
 */
class InventorySftpClient
{
    public const int DEFAULT_PORT = 22;
    public const int CONNECT_TIMEOUT_SECONDS = 15;

    private SFTP $sftp;

    /**
     * @param array{host:string,username:string,password:string,port?:int} $config
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $port = $config['port'] ?? self::DEFAULT_PORT;

        if ($host === '' || $username === '' || $password === '') {
            throw new ValidationException('Cloud sync FTP configuration is missing host / username / password');
        }

        $this->sftp = new SFTP($host, $port, self::CONNECT_TIMEOUT_SECONDS);

        if (! $this->sftp->login($username, $password)) {
            throw new ValidationException('Could not connect to inventory source');
        }
    }

    /**
     * List filenames in the SFTP root, excluding the `.`/`..` entries.
     *
     * @return list<string>
     */
    public function listRoot(): array
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

    /**
     * Read a remote file into memory as a string. Returns null when missing.
     */
    public function read(string $remotePath): ?string
    {
        try {
            return $this->sftp->get($remotePath);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function disconnect(): void
    {
        $this->sftp->disconnect();
    }
}
