<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Override;

class DriveCentricHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);
        $this->app->set(ConfigurationEnum::API_SECRET_KEY->value, $this->data['api_secret_key']);
        $this->company->set(ConfigurationEnum::STORE_ID->value, $this->data['store_id']);
        new Client($this->app, $this->company)->getClient();
        ;
        return true;
    }
}
