<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Override;

class VinSolutionHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        if (! empty($this->data['client_id'])) {
            $this->app->set(ConfigurationEnum::CLIENT_ID->value, $this->data['client_id']);
        }

        if (! empty($this->data['client_secret'])) {
            $this->app->set(ConfigurationEnum::CLIENT_SECRET->value, $this->data['client_secret']);
        }

        if (! empty($this->data['api_key'])) {
            $this->app->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);
        }

        if (! empty($this->data['api_key_digital_showroom'])) {
            $this->app->set(ConfigurationEnum::API_KEY_DIGITAL_SHOWROOM->value, $this->data['api_key_digital_showroom']);
        }
        //  $this->app->set(ConfigurationEnum::COMPANY->value, $this->data['company_id']);
        //   $this->app->set(ConfigurationEnum::USER->value, $this->data['user_id']);
        $this->company->set(ConfigurationEnum::COMPANY->value, $this->data['dealer_id']);
        $this->company->user->set(ConfigurationEnum::getUserKey($this->company, $this->company->user), $this->data['user_id']);

        $client = new Client(
            (int) $this->data['dealer_id'],
            (int) $this->data['user_id'],
            $this->app
        );

        $response = $client->auth();

        return ! empty($response['access_token']);
    }
}
