<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulance\Handlers;

use Kanvas\Connectors\AeroAmbulance\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;

class AeroAmbulanceHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $email = $this->data['areo_ambulance_email'] ?? null;
        $password = $this->data['areo_ambulance_password'] ?? null;
        $baseUrl = $this->data['areo_ambulance_api_url'] ?? null;

        $this->app->set(ConfigurationEnum::EMAIL->value, $email);
        $this->app->set(ConfigurationEnum::PASSWORD->value, $password);
        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);

        return $email !== null && $password !== null && $baseUrl !== null;
    }
}
