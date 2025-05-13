<?php
declare(strict_types=1);


namespace Kanvas\Connectors\DriveCentric\Handlers;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Override;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
class DriveCentricHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);
        $this->app->set(ConfigurationEnum::API_SECRET_KEY->value, $this->data['api_secret_key']);
        
        return true;
    }
}