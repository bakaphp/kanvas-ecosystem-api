<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WaSender;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class ClientAuthKeyTest extends TestCase
{
    private function readApiKey(Client $client): string
    {
        return (string) new ReflectionProperty(Client::class, 'apiKey')->getValue($client);
    }

    /**
     * The base URL is typed into a form, so the probe never reaches an address inside the network.
     */
    public function testCredentialsAreNotProbedAtAnAddressInsideTheNetwork(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not allowed to reach');

        Client::validateCredentials('http://169.254.169.254', 'any-key');
    }

    /**
     * A setting never written reads back as null and an all-digit key as an int: neither may reach
     * a string property, and a company with nothing configured has to hear so.
     */
    public function testMissingSettingsAreAValidationErrorAndANumericKeyIsRead(): void
    {
        $settings = static function (string $class, array $values): object {
            $holder = Mockery::mock($class);
            $holder->shouldReceive('get')->andReturnUsing(fn (string $key): mixed => $values[$key] ?? null);

            return $holder;
        };

        $client = new Client(
            $settings(AppInterface::class, [ConfigurationEnum::BASE_URL->value => 'https://wasender.test']),
            $settings(CompanyInterface::class, [ConfigurationEnum::API_KEY->value => 123456])
        );

        $this->assertSame('123456', $this->readApiKey($client));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Wasender configuration is missing');

        new Client($settings(AppInterface::class, []), $settings(CompanyInterface::class, []));
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
