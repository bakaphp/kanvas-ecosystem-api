<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Illuminate\Support\Facades\Bus;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Users\Models\Users;
use Tests\Connectors\Integration\Azul\Concerns\BuildsAzulCertificate;
use Tests\TestCase;

class AzulClientFailoverTest extends TestCase
{
    use BuildsAzulCertificate;

    private string $certPem;
    private string $keyPem;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->certPem, $this->keyPem] = $this->generateCertificate();
    }

    public function createUser(): Users
    {
        Bus::fake();

        return parent::createUser();
    }

    public function testSandboxHasNoFailoverHost(): void
    {
        $client = $this->client(ConfigurationEnum::SANDBOX_URL->value);

        $this->assertNull($client->failoverEndpoint(ConfigurationEnum::SANDBOX_URL->value));
    }

    public function testProductionFallsBackToAzulSecondaryHost(): void
    {
        $client = $this->client(ConfigurationEnum::PROD_URL->value);

        $this->assertSame(
            ConfigurationEnum::PROD_FAILOVER_URL->value,
            $client->failoverEndpoint(ConfigurationEnum::PROD_URL->value)
        );
    }

    public function testFailoverPreservesTheTransactionQuerySuffix(): void
    {
        $client = $this->client(ConfigurationEnum::PROD_URL->value);

        $this->assertSame(
            ConfigurationEnum::PROD_FAILOVER_URL->value . '?ProcessPost',
            $client->failoverEndpoint($client->getPostUrl())
        );

        $this->assertSame(
            ConfigurationEnum::PROD_FAILOVER_URL->value . '?VerifyPayment',
            $client->failoverEndpoint($client->getVerifyUrl())
        );
    }

    public function testConfiguredFailoverUrlOverridesTheDefault(): void
    {
        $client = $this->client(ConfigurationEnum::PROD_URL->value, [
            'failover_url' => 'https://failover.example.com/WebServices/JSON/Default.aspx',
        ]);

        $this->assertSame(
            'https://failover.example.com/WebServices/JSON/Default.aspx?ProcessVoid',
            $client->failoverEndpoint($client->getVoidUrl())
        );
    }

    public function testFailoverIsDisabledWhenItPointsAtThePrimary(): void
    {
        $client = $this->client(ConfigurationEnum::PROD_URL->value, [
            'failover_url' => ConfigurationEnum::PROD_URL->value,
        ]);

        $this->assertNull($client->failoverEndpoint(ConfigurationEnum::PROD_URL->value));
    }

    public function testCustomBaseUrlIsTreatedAsNonProduction(): void
    {
        $client = $this->client('https://staging.azul.example.com/WebServices/JSON/Default.aspx');

        $this->assertNull($client->failoverEndpoint('https://staging.azul.example.com/WebServices/JSON/Default.aspx'));
    }

    private function client(string $baseUrl, array $overrides = []): Client
    {
        return new Client(
            $this->appWithoutSettings(),
            Companies::first(),
            $overrides + [
                'base_url' => $baseUrl,
                'auth1' => 'test-auth1',
                'auth2' => 'test-auth2',
                'cert' => $this->certPem,
                'key' => $this->keyPem,
                'verify_ssl' => false,
            ]
        );
    }
}
