<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy;

use Baka\Contracts\AppInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\BrushCrazy\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use PDO;

/**
 * Read-only connection to the legacy BrushCrazy MySQL database, built at runtime from app
 * settings. Never cached in a static property — under Octane a long-lived worker would keep
 * serving a rotated credential (see src/Domains/Connectors/CLAUDE.md).
 */
class Client
{
    protected ConnectionInterface $connection;

    public function __construct(AppInterface $app)
    {
        $host = $app->get(ConfigurationEnum::BRUSHCRAZY_DB_HOST->value);
        $database = $app->get(ConfigurationEnum::BRUSHCRAZY_DB_DATABASE->value);

        if (! $host || ! $database) {
            throw new ValidationException('BrushCrazy database configuration is missing. Run the BrushCrazy connector setup first.');
        }

        // Named per app so two tenants pointing at different BrushCrazy databases can't share
        // (and clobber) one connection entry.
        $name = self::connectionName($app);

        Config::set('database.connections.' . $name, [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $app->get(ConfigurationEnum::BRUSHCRAZY_DB_PORT->value) ?? '3306',
            'database' => $database,
            'username' => $app->get(ConfigurationEnum::BRUSHCRAZY_DB_USERNAME->value) ?? 'root',
            'password' => $app->get(ConfigurationEnum::BRUSHCRAZY_DB_PASSWORD->value) ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'options' => self::pdoOptions($app, (string) $host),
        ]);

        DB::purge($name);
        $this->connection = DB::connection($name);
    }

    /**
     * @return array<int, mixed>
     */
    private static function pdoOptions(AppInterface $app, string $host): array
    {
        $sslCa = $app->get(ConfigurationEnum::BRUSHCRAZY_DB_SSL_CA->value);

        if (! $sslCa) {
            return [];
        }

        $options = [PDO::MYSQL_ATTR_SSL_CA => $sslCa];

        // Reaching the instance through an SSH tunnel means connecting to localhost, which never
        // matches the certificate's RDS hostname. The tunnel already encrypts the hop, so verify
        // the CA without the hostname check instead of failing the handshake.
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }

        return $options;
    }

    public static function connectionName(AppInterface $app): string
    {
        return 'brushcrazy_read_' . $app->getId();
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }

    public function table(string $table): Builder
    {
        return $this->connection->table($table);
    }

    public function testConnection(): bool
    {
        $this->connection->select('SELECT 1');

        return true;
    }
}
