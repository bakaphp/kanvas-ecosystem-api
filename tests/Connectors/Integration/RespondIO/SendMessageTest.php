<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\RespondIO;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\RespondIO\Client;
use Kanvas\Connectors\RespondIO\Enums\ConfigurationEnum;
use Tests\TestCase;

final class SendMessageTest extends TestCase
{
    public function testSendingMessage(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app->set(ConfigurationEnum::BEAR_TOKEN_AUTH->value, getenv('TEST_RESPOND_IO'));

        $client = new Client($app, $company);
        $response = $client->sendMessage('18294428902', 'Hello from Kanvas!');

        $this->assertArrayHasKey('contactId', $response);
        $this->assertArrayHasKey('messageId', $response);
    }
}
