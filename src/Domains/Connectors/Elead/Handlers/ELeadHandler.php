<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Override;

class ELeadHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $this->company->set(CustomFieldEnum::COMPANY->value, $this->data['subscription_id']);
        $this->app->set(ConfigurationEnum::ELEAD_API_KEY->value, $this->data['api_key']);
        $this->app->set(ConfigurationEnum::ELEAD_API_SECRET->value, $this->data['api_secret']);
        $this->app->set(ConfigurationEnum::ELEAD_DEV_MODE->value, $this->data['dev_mode']);

        $client = new Client(
            $this->app,
            $this->company
        );

        $response = $client->auth();

        return ! empty($response['access_token']);
    }
}
