<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul\Handlers;

use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class AzulHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $auth1       = $this->data['auth1'] ?? null;
        $auth2       = $this->data['auth2'] ?? null;
        $store       = $this->data['store'] ?? null;
        $channel     = $this->data['channel'] ?? null;
        $baseUrl     = $this->data['base_url'] ?? null;
        $failoverUrl = $this->data['failover_url'] ?? null;

        if (empty($auth1) || empty($auth2) || empty($store) || empty($channel)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::AZUL_AUTH1->value, $auth1);
        $this->app->set(ConfigurationEnum::AZUL_AUTH2->value, $auth2);
        $this->app->set(ConfigurationEnum::AZUL_STORE->value, $store);
        $this->app->set(ConfigurationEnum::AZUL_CHANNEL->value, $channel);

        if ($baseUrl !== null) {
            $this->app->set(ConfigurationEnum::AZUL_BASE_URL->value, $baseUrl);
        }

        if ($failoverUrl !== null) {
            $this->app->set(ConfigurationEnum::AZUL_FAILOVER_URL->value, $failoverUrl);
        }

        new Client($this->app, $this->company);

        return true;
    }
}
