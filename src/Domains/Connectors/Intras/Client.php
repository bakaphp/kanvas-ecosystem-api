<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras;

use Baka\Contracts\AppInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected ConnectionInterface $connection;

    public function __construct(AppInterface $app)
    {
        $host = $app->get(ConfigurationEnum::INTRAS_DB_HOST->value);
        $database = $app->get(ConfigurationEnum::INTRAS_DB_DATABASE->value);

        if (! $host || ! $database) {
            throw new ValidationException('Intras database configuration is missing. Run the Intras connector setup first.');
        }

        Config::set('database.connections.intras', [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $app->get(ConfigurationEnum::INTRAS_DB_PORT->value) ?? '3306',
            'database' => $database,
            'username' => $app->get(ConfigurationEnum::INTRAS_DB_USERNAME->value) ?? 'root',
            'password' => $app->get(ConfigurationEnum::INTRAS_DB_PASSWORD->value) ?? '',
            'charset' => 'utf8',
            'collation' => 'utf8_general_ci',
            'prefix' => '',
            'strict' => false,
        ]);

        DB::purge('intras');
        $this->connection = DB::connection('intras');
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
