<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Tests\Connectors\Traits\HasWaSenderConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use HasWaSenderConfiguration;

    public function testAuth()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $waClient = $this->getClient($app, $company);

        $result = Client::validateCredentials(
            $app->get(ConfigurationEnum::BASE_URL->value),
            $app->get(ConfigurationEnum::API_KEY->value)
        );

        $this->assertTrue($result);
    }
}
