<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica;

use Baka\Contracts\AppInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class SqlClient
{
    public static function connectionName(AppInterface $app): string
    {
        return 'acumatica_read_' . (string) $app->getId();
    }

    public static function connection(AppInterface $app): ConnectionInterface
    {
        $config = $app->get(ConfigurationEnum::ACUMATICA_CONFIG->value);

        if (empty($config) || empty($config['sqlHost']) || empty($config['sqlDatabase'])) {
            throw new ValidationException('Acumatica SQL read configuration is missing.');
        }

        $name = self::connectionName($app);

        config([
            'database.connections.' . $name => [
                'driver' => 'sqlsrv',
                'host' => $config['sqlHost'],
                'port' => (int) ($config['sqlPort'] ?? 1433),
                'database' => $config['sqlDatabase'],
                'username' => $config['sqlUsername'] ?? '',
                'password' => $config['sqlPassword'] ?? '',
                'charset' => 'utf8',
                'prefix' => '',
                'prefix_indexes' => true,
                'encrypt' => 'yes',
                'trust_server_certificate' => 'true',
            ],
        ]);

        DB::purge($name);

        return DB::connection($name);
    }

    public static function isConfigured(AppInterface $app): bool
    {
        $config = $app->get(ConfigurationEnum::ACUMATICA_CONFIG->value);

        return ! empty($config['sqlHost']) && ! empty($config['sqlDatabase']);
    }
}
