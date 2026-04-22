<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class IntrasHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $host = $this->data['intras_db_host'] ?? null;
        $database = $this->data['intras_db_database'] ?? null;
        $username = $this->data['intras_db_username'] ?? null;
        $password = $this->data['intras_db_password'] ?? null;

        if ($host === null || $database === null) {
            throw new ValidationException('Intras database host and database name are required.');
        }

        $this->app->set(ConfigurationEnum::INTRAS_DB_HOST->value, $host);
        $this->app->set(ConfigurationEnum::INTRAS_DB_DATABASE->value, $database);
        $this->app->set(ConfigurationEnum::INTRAS_DB_USERNAME->value, $username);
        $this->app->set(ConfigurationEnum::INTRAS_DB_PASSWORD->value, $password);
        $this->app->set(ConfigurationEnum::INTRAS_SYNC_ENABLED->value, true);

        $client = new Client($this->app);

        return $client->testConnection();
    }
}
