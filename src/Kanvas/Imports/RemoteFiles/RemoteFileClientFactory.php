<?php

declare(strict_types=1);

namespace Kanvas\Imports\RemoteFiles;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrl;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Imports\Enums\ImportDriverEnum;
use Kanvas\Imports\Models\ImportConnection;

/**
 * The only place an import connection is opened. The host is user-entered (company admins can
 * create connections), so it is resolved through the SSRF guard and the client connects to that
 * resolved IP, never the hostname — a DNS rebind after the check can't swap in a private address.
 */
class RemoteFileClientFactory
{
    public const array ALLOWED_PORTS = [21, 22, 2121, 2222];

    public function make(ImportConnection $connection, ?string $root = null): RemoteFileClient
    {
        $ip = self::assertConnectable($connection->host, $connection->port)[0];

        return match ($connection->driver) {
            ImportDriverEnum::FTP => new FtpRemoteFileClient(
                host: $ip,
                port: $connection->port,
                username: $connection->username,
                password: $connection->password,
                root: $root ?? $connection->root,
                passive: $connection->passive,
            ),
            ImportDriverEnum::SFTP => new SftpRemoteFileClient(
                host: $ip,
                port: $connection->port,
                username: $connection->username,
                password: $connection->password,
                root: $root ?? $connection->root,
            ),
        };
    }

    /**
     * @return list<string> the public IPs the host resolves to
     */
    public static function assertConnectable(string $host, int $port): array
    {
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new ValidationException('Port ' . $port . ' is not allowed. Allowed: ' . implode(', ', self::ALLOWED_PORTS));
        }

        // Rethrown as a validation error so an admin sees why the host was refused, not a 500.
        try {
            return SafeUrl::resolvePublicHost($host);
        } catch (SsrfException $e) {
            throw new ValidationException($e->getMessage());
        }
    }
}
