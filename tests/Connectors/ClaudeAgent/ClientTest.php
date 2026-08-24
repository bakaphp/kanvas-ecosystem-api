<?php

declare(strict_types=1);

namespace Tests\Connectors\ClaudeAgent;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Exceptions\ValidationException;
use Tests\Connectors\Traits\HasClaudeAgentConfiguration;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    use DatabaseTransactions;
    use HasClaudeAgentConfiguration;

    /** Settings live on mysql; agents, types and sessions on intelligence. */
    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $currentApp;
    private Companies $currentCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
    }

    public function testListAgentsDecodesTheResponse(): void
    {
        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(200, [
                'data' => [
                    ['id' => 'agent_01abc', 'name' => 'Claude Agent', 'version' => 3],
                ],
                'next_page' => null,
            ]),
        ]);

        $response = $client->listAgents();

        $this->assertSame('agent_01abc', $response['data'][0]['id']);
        $this->assertNull($response['next_page']);
    }

    public function testCompanyKeyWinsOverAppKey(): void
    {
        $this->currentApp->set(ConfigurationEnum::API_KEY->value, 'sk-ant-app-level');
        $this->currentCompany->set(ConfigurationEnum::API_KEY->value, 'sk-ant-company-level');

        $this->assertSame(
            'sk-ant-company-level',
            Client::resolveApiKey($this->currentApp, $this->currentCompany),
        );
    }

    public function testFallsBackToTheAppKeyWhenTheCompanyHasNone(): void
    {
        $this->currentApp->set(ConfigurationEnum::API_KEY->value, 'sk-ant-app-level');
        $this->currentCompany->set(ConfigurationEnum::API_KEY->value, '');

        $this->assertSame(
            'sk-ant-app-level',
            Client::resolveApiKey($this->currentApp, $this->currentCompany),
        );
    }

    public function testBaseUrlDefaultsToThePublicApi(): void
    {
        $this->currentApp->set(ConfigurationEnum::BASE_URL->value, '');

        $this->assertSame(Client::DEFAULT_BASE_URL, Client::resolveBaseUrl($this->currentApp));
    }

    public function testBaseUrlOverrideIsTrimmedOfTrailingSlash(): void
    {
        $this->currentApp->set(ConfigurationEnum::BASE_URL->value, 'https://proxy.internal/');

        $this->assertSame('https://proxy.internal', Client::resolveBaseUrl($this->currentApp));
    }

    public function testConstructionFailsWhenNoKeyIsConfigured(): void
    {
        $this->clearClaudeAgentConfiguration($this->currentApp, $this->currentCompany);

        $this->expectException(ValidationException::class);

        new Client($this->currentApp, $this->currentCompany);
    }

    /**
     * getCode() is always 0 on Kanvas' ValidationException, so anything branching on the HTTP
     * status has to read ->status. Guard that it is actually populated.
     */
    public function testApiErrorsCarryTheHttpStatusAndTheApiMessage(): void
    {
        $client = $this->claudeAgentClientReturning($this->currentApp, $this->currentCompany, [
            $this->claudeAgentJsonResponse(401, [
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ]),
        ]);

        try {
            $client->listAgents();
            $this->fail('Expected a ClaudeAgentApiException.');
        } catch (ClaudeAgentApiException $e) {
            $this->assertSame(401, $e->status);
            $this->assertStringContainsString('invalid x-api-key', $e->getMessage());
        }
    }

    public function testValidateCredentialsPassesOnASuccessfulProbe(): void
    {
        $guzzle = $this->claudeAgentGuzzleReturning([
            $this->claudeAgentJsonResponse(200, ['data' => [], 'next_page' => null]),
        ]);

        $this->assertTrue(Client::validateCredentials('sk-ant-valid', Client::DEFAULT_BASE_URL, $guzzle));
    }

    public function testValidateCredentialsRejectsABadKey(): void
    {
        $guzzle = $this->claudeAgentGuzzleReturning([
            $this->claudeAgentJsonResponse(401, [
                'type' => 'error',
                'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
            ]),
        ]);

        $this->expectException(ClaudeAgentApiException::class);

        Client::validateCredentials('sk-ant-bad', Client::DEFAULT_BASE_URL, $guzzle);
    }

    public function testValidateCredentialsRejectsAnEmptyKeyWithoutCallingTheApi(): void
    {
        $this->expectException(ValidationException::class);

        Client::validateCredentials('   ', Client::DEFAULT_BASE_URL);
    }

    /**
     * A transport failure never reached the API, so there is no HTTP status to report — status 0
     * is the documented marker for that, and callers must not read it as a 4xx.
     */
    public function testTransportFailuresSurfaceAsStatusZero(): void
    {
        $guzzle = $this->claudeAgentGuzzleReturning([
            new ConnectException('Connection refused', new Request('GET', '/v1/agents')),
        ]);

        try {
            Client::validateCredentials('sk-ant-valid', Client::DEFAULT_BASE_URL, $guzzle);
            $this->fail('Expected a ClaudeAgentApiException.');
        } catch (ClaudeAgentApiException $e) {
            $this->assertSame(0, $e->status);
        }
    }
}
