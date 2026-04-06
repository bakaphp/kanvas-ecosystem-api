<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;

trait HasVoiceBridgeConfiguration
{
    public function getClient(AppInterface $app): Client
    {
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_VOICE_BRIDGE_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, getenv('TEST_VOICE_BRIDGE_BASE_URL'));
        $app->set(ConfigurationEnum::COMPANY_ID->value, getenv('TEST_VOICE_BRIDGE_COMPANY_ID'));

        return Client::getInstance($app);
    }
}
