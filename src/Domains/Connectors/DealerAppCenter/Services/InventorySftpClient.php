<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Services;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\RemoteFiles\SftpRemoteFileClient;

/**
 * The cloud-sync webhook's view of the generic SFTP client: it takes the connector's array config
 * and keeps the error messages dealer-api shows its users verbatim.
 */
class InventorySftpClient extends SftpRemoteFileClient
{
    public const int DEFAULT_PORT = 22;

    /**
     * @param array{host:string,username:string,password:string,port?:int} $config
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';

        if ($host === '' || $username === '' || $password === '') {
            throw new ValidationException('Cloud sync FTP configuration is missing host / username / password');
        }

        try {
            parent::__construct(
                $host,
                $config['port'] ?? self::DEFAULT_PORT,
                $username,
                $password
            );
        } catch (ValidationException) {
            throw new ValidationException('Could not connect to inventory source');
        }
    }

    /**
     * @return list<string>
     */
    public function listRoot(): array
    {
        return $this->listFiles();
    }
}
