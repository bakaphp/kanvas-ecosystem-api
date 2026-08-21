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
        $bearerToken = getenv('TEST_RESPOND_IO');

        if (! is_string($bearerToken) || $bearerToken === '') {
            $this->markTestSkipped('TEST_RESPOND_IO is not configured.');
        }

        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app->set(ConfigurationEnum::BEARER_TOKEN->value, $bearerToken);

        //$client = new Client($app, $company);
        //@todo figure out how to mock this
        //$response = $client->sendMessage(getenv('TEST_RESPOND_IO_PHONE'), 'Hello from Kanvas!');

        //$this->assertArrayHasKey('contactId', $response);
        //$this->assertArrayHasKey('messageId', $response);
    }
}
