<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Client;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
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

    public function testEndpointOutsideTheBaseUrlHasNoFailover(): void
    {
        $client = $this->client(ConfigurationEnum::PROD_URL->value);

        $this->assertNull($client->failoverEndpoint('https://other.example.com/WebServices/JSON/Default.aspx'));
    }

    public function testConnectionRefusedIsRetriedOnTheFailoverHost(): void
    {
        $history = [];
        $client = $this->mockedClient(
            [
                $this->connectError(CURLE_COULDNT_CONNECT),
                new Response(200, [], json_encode(['ResponseCode' => 'ISO8583'])),
            ],
            $history,
        );

        $this->assertSame(['ResponseCode' => 'ISO8583'], $client->post(['CustomOrderId' => 'A1'], $client->getPostUrl()));
        $this->assertCount(2, $history);
        $this->assertSame('contpagos.azul.com.do', $history[1]['request']->getUri()->getHost());
    }

    public function testTimeoutIsNeverRetriedBecauseTheChargeMayHaveLanded(): void
    {
        $history = [];
        $client = $this->mockedClient([$this->connectError(CURLE_OPERATION_TIMEDOUT)], $history);

        try {
            $client->post(['CustomOrderId' => 'A1'], $client->getPostUrl());
            $this->fail('A timeout must surface instead of being replayed.');
        } catch (AzulException) {
            $this->assertCount(1, $history);
        }
    }

    public function testFailureOnBothHostsSurfacesOneAzulException(): void
    {
        $history = [];
        $client = $this->mockedClient(
            [
                $this->connectError(CURLE_COULDNT_RESOLVE_HOST),
                $this->connectError(CURLE_COULDNT_CONNECT),
            ],
            $history,
        );

        $this->expectException(AzulException::class);
        $this->expectExceptionMessage('both the primary and failover endpoints');

        $client->post(['CustomOrderId' => 'A1'], $client->getPostUrl());
    }

    private function mockedClient(array $responses, array &$history): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        $client = new class ($this->appWithoutSettings(), Companies::first(), $this->config(ConfigurationEnum::PROD_URL->value)) extends Client {
            public function withGuzzle(GuzzleClient $guzzle): static
            {
                $this->client = $guzzle;

                return $this;
            }
        };

        return $client->withGuzzle(new GuzzleClient(['handler' => $stack]));
    }

    private function connectError(int $errno): ConnectException
    {
        return new ConnectException(
            "cURL error {$errno}",
            new Request('POST', ConfigurationEnum::PROD_URL->value),
            null,
            ['errno' => $errno],
        );
    }

    private function client(string $baseUrl, array $overrides = []): Client
    {
        return new Client($this->appWithoutSettings(), Companies::first(), $this->config($baseUrl, $overrides));
    }

    private function config(string $baseUrl, array $overrides = []): array
    {
        return $overrides + [
            'base_url' => $baseUrl,
            'auth1' => 'test-auth1',
            'auth2' => 'test-auth2',
            'cert' => $this->certPem,
            'key' => $this->keyPem,
            'verify_ssl' => false,
        ];
    }
}
