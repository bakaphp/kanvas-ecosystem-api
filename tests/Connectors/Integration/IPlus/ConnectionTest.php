<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\IPlus;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Client;
use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Tests\TestCase;

final class ConnectionTest extends TestCase
{
    public function testConnection()
    {
        $app = app(Apps::class);
        $app->set(ConfigurationEnum::AUTH_BASE_URL->value, getenv('TEST_IPLUS_BASE_URL'));
        $app->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_IPLUS_CLIENT_ID'));
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_IPLUS_CLIENT_SECRET'));

        $client = new Client($app);
        $token = $client->getValidAccessToken();

        $this->assertIsString($token);
    }
}
