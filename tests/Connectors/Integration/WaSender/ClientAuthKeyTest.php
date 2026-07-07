<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use ReflectionProperty;
use Tests\TestCase;

class ClientAuthKeyTest extends TestCase
{
    private function readApiKey(Client $client): string
    {
        return (string) new ReflectionProperty(Client::class, 'apiKey')->getValue($client);
    }

    public function testDefaultsToApiKeyButUsesOverrideWhenProvided(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        // Client reads the company API_KEY first — the per-session send key (MessageService path).
        $app->set(ConfigurationEnum::BASE_URL->value, 'https://wasender.test');
        $company->set(ConfigurationEnum::API_KEY->value, 'SESSION_SEND_KEY');

        try {
            // No override → the send key (default send path, unchanged).
            $sendClient = new Client($app, $company);
            $this->assertSame('SESSION_SEND_KEY', $this->readApiKey($sendClient));

            // Override → the account PAT (SessionService passes this for session management).
            $mgmtClient = new Client($app, $company, false, 'ACCOUNT_PAT');
            $this->assertSame('ACCOUNT_PAT', $this->readApiKey($mgmtClient));

            // Empty override falls back to the configured key (PAT unset → legacy behavior).
            $fallbackClient = new Client($app, $company, false, '');
            $this->assertSame('SESSION_SEND_KEY', $this->readApiKey($fallbackClient));
        } finally {
            $app->set(ConfigurationEnum::BASE_URL->value, '');
            $company->set(ConfigurationEnum::API_KEY->value, '');
        }
    }
}
