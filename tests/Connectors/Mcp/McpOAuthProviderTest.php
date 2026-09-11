<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Contracts\OAuthProviderFactory;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Mcp\Actions\CreateMcpOAuthReceiverAction;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Enums\McpConnectionStatusEnum;
use Kanvas\Connectors\Mcp\OAuth\McpOAuthProvider;
use Kanvas\Connectors\Mcp\Services\McpConnectionService;
use Kanvas\Connectors\Mcp\Services\McpOAuthDiscoveryService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use NeuronAI\MCP\McpTransportInterface;
use Override;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

/**
 * The whole OAuth connection of one agent end to end, with the authorization server faked over Http and
 * the MCP server faked over its transport — no socket is opened. Hosts are IP literals so the SSRF guard
 * still runs for real without depending on DNS.
 */
final class McpOAuthProviderTest extends McpTestCase
{
    private const string MCP_URL = 'https://8.8.8.8/mcp';

    private const string AUTH_SERVER = 'https://1.1.1.1';

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );
    }

    public function testTheFactoryResolvesMcpReceivers(): void
    {
        $receiver = $this->receiverFor($this->makeAgent(), $this->oauthTool());

        $this->assertInstanceOf(McpOAuthProvider::class, OAuthProviderFactory::make($receiver));
    }

    public function testTheAuthorizationUrlCarriesPkceStateAndResource(): void
    {
        $this->fakeAuthorizationServer();
        $receiver = $this->receiverFor($this->makeAgent(), $this->oauthTool());

        $url = new McpOAuthProvider()->getAuthorizationUrl(
            $receiver,
            $this->mcpApp,
            Request::create('/'),
            'nonce-123'
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::AUTH_SERVER . '/authorize?', $url);
        $this->assertSame('nonce-123', $query['state']);
        $this->assertSame('registered-client', $query['client_id']);
        $this->assertSame($receiver->getOAuthCallbackUrl(), $query['redirect_uri']);
        $this->assertSame(self::MCP_URL, $query['resource']);
        $this->assertSame('S256', $query['code_challenge_method']);

        // The verifier stays server-side; only its S256 challenge travels in the URL.
        $verifier = (string) Cache::get('mcp_oauth_pkce:nonce-123');
        $this->assertNotSame('', $verifier);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
    }

    public function testResourceIsLeftOutOfTheConsentUrlWhenTheServerIsConfiguredTo(): void
    {
        $this->fakeAuthorizationServer();
        $integration = $this->makeIntegration([
            'url' => self::MCP_URL,
            'auth_methods' => ['oauth'],
            'oauth' => ['include_resource' => false],
        ]);

        $url = new McpOAuthProvider()->getAuthorizationUrl(
            $this->receiverFor($this->makeAgent(), $this->makeMcpTool($integration)),
            $this->mcpApp,
            Request::create('/'),
            'nonce-no-resource'
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('resource', $query);
        $this->assertSame('S256', $query['code_challenge_method']);
    }

    public function testTheClientIsRegisteredOnceAsAPublicClientAndThenReused(): void
    {
        $this->fakeAuthorizationServer();
        $receiver = $this->receiverFor($this->makeAgent(), $this->oauthTool());
        $provider = new McpOAuthProvider();

        $provider->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'first');
        $provider->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'second');

        $registrations = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/register'))
            ->count();

        $this->assertSame(1, $registrations);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/register')
            && $request['token_endpoint_auth_method'] === 'none'
            && $request['redirect_uris'] === [$receiver->getOAuthCallbackUrl()]);
    }

    public function testPinningAnotherAuthorizationServerRegistersANewClientThere(): void
    {
        Http::fake([
            'https://8.8.8.8/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => self::MCP_URL,
                'authorization_servers' => [self::AUTH_SERVER],
            ]),
            self::AUTH_SERVER . '/.well-known/oauth-authorization-server' => Http::response([
                'authorization_endpoint' => self::AUTH_SERVER . '/authorize',
                'token_endpoint' => self::AUTH_SERVER . '/token',
                'registration_endpoint' => self::AUTH_SERVER . '/register',
            ]),
            self::AUTH_SERVER . '/register' => Http::response(['client_id' => 'client-at-advertised'], 201),
            'https://9.9.9.9/.well-known/oauth-authorization-server' => Http::response([
                'authorization_endpoint' => 'https://9.9.9.9/authorize',
                'token_endpoint' => 'https://9.9.9.9/token',
                'registration_endpoint' => 'https://9.9.9.9/register',
            ]),
            'https://9.9.9.9/register' => Http::response(['client_id' => 'client-at-pinned'], 201),
            '*' => Http::response('', 404),
        ]);

        $integration = $this->oauthIntegration();
        $receiver = $this->receiverFor($this->makeAgent(), $this->makeMcpTool($integration));
        $provider = new McpOAuthProvider();

        $provider->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'before');

        $integration->metadata = [
            ...$integration->metadata,
            'oauth' => ['authorization_server' => 'https://9.9.9.9'],
        ];
        $integration->saveOrFail();
        new McpOAuthDiscoveryService($integration)->forget();

        $url = $provider->getAuthorizationUrl($receiver->refresh(), $this->mcpApp, Request::create('/'), 'after');

        // The client registered at the first server does not exist at the second one.
        $this->assertStringStartsWith('https://9.9.9.9/authorize?', $url);
        $this->assertStringContainsString('client_id=client-at-pinned', $url);
    }

    public function testAClientSetByHandIsUsedWithoutRegistering(): void
    {
        $this->fakeAuthorizationServer(registration: false);
        $integration = $this->oauthIntegration();
        $this->mcpApp->set(
            ConfigurationEnum::OAUTH_CLIENT_ID_PREFIX->forIntegration($integration->getId()),
            'console-client'
        );

        $url = new McpOAuthProvider()->getAuthorizationUrl(
            $this->receiverFor($this->makeAgent(), $this->makeMcpTool($integration)),
            $this->mcpApp,
            Request::create('/'),
            'nonce'
        );

        $this->assertStringContainsString('client_id=console-client', $url);
        Http::assertNotSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/register'));
    }

    public function testACallbackThatWasNeverStartedHereIsRejected(): void
    {
        $receiver = $this->receiverFor($this->makeAgent(), $this->oauthTool());

        // The shared controller never compares state to its nonce; the verifier lookup is the check.
        $this->expectException(ValidationException::class);

        new McpOAuthProvider()->handleCallback(
            $receiver,
            $this->mcpApp,
            Request::create('/callback', 'GET', ['code' => 'stolen', 'state' => 'forged'])
        );
    }

    public function testADeclinedConsentSurfacesTheVendorsReason(): void
    {
        $receiver = $this->receiverFor($this->makeAgent(), $this->oauthTool());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('access_denied');

        new McpOAuthProvider()->handleCallback(
            $receiver,
            $this->mcpApp,
            Request::create('/callback', 'GET', ['error' => 'access_denied'])
        );
    }

    public function testTheCallbackConnectsTheServerForThatAgentAlone(): void
    {
        $this->fakeAuthorizationServer();
        $integration = $this->oauthIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $bystander = $this->makeAgent();

        $this->connect($this->receiverFor($agent, $tool));

        $id = $integration->getId();

        // All three, not just the access token: without the refresh token and expiry the grant dies
        // within the hour with nothing to renew it.
        $credentials = $this->credentials($agent, $integration);
        $this->assertSame('access-1', $credentials->rawToken());
        $this->assertSame('refresh-1', $credentials->refreshToken());
        $this->assertNotNull($credentials->expiresAt());

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/token')
            && $request->isForm()
            && $request['grant_type'] === 'authorization_code'
            && $request['code'] === 'the-code'
            && $request['resource'] === self::MCP_URL
            && is_string($request['code_verifier'] ?? null));

        // Connecting is granting: the agent now holds the tool, and the runtime switch is on.
        $this->assertSame(McpConnectionStatusEnum::ACTIVE->value, $this->connectionState($agent, $tool)['status']);
        $this->assertSame('oauth', $this->connectionState($agent, $tool)['auth']);
        $this->assertContains($tool->getId(), $agent->selectedTools()->get()->modelKeys());

        $this->assertNull($this->credentials($bystander, $integration)->rawToken());
        $this->assertNull($this->grantFor($bystander, $tool));
        $this->assertNull(
            $this->mcpCompany->get(ConfigurationEnum::CREDENTIALS_PREFIX->forIntegration($id)),
            'No company-wide credential exists any more.'
        );
    }

    public function testReconnectingAFailedConnectionTurnsItBackOn(): void
    {
        $this->fakeAuthorizationServer();
        $integration = $this->oauthIntegration();
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $receiver = $this->receiverFor($agent, $tool);

        $this->connect($receiver);
        new McpConnectionService($agent, $integration)->markFailed('revoked at the vendor');
        $this->connect($receiver);

        $state = $this->connectionState($agent, $tool);
        $this->assertSame(McpConnectionStatusEnum::ACTIVE->value, $state['status']);
        $this->assertNull($state['last_error']);
    }

    public function testACustomerFacingAgentNeverReachesTheConsentScreen(): void
    {
        $this->fakeAuthorizationServer();
        $receiver = $this->receiverFor($this->makeAgent(SalesAgent::class), $this->oauthTool());

        $this->expectException(ValidationException::class);

        new McpOAuthProvider()->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'nonce');
    }

    private function connect(ReceiverWebhook $receiver): void
    {
        $provider = new class () extends McpOAuthProvider {
            #[Override]
            protected function transportFor(): ?McpTransportInterface
            {
                return FakeMcpServer::listing(FakeMcpServer::twoTools());
            }
        };

        $provider->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'nonce-ok');
        $provider->handleCallback(
            $receiver,
            $this->mcpApp,
            Request::create('/callback', 'GET', ['code' => 'the-code', 'state' => 'nonce-ok'])
        );
    }

    private function fakeAuthorizationServer(bool $registration = true): void
    {
        $metadata = [
            'issuer' => self::AUTH_SERVER,
            'authorization_endpoint' => self::AUTH_SERVER . '/authorize',
            'token_endpoint' => self::AUTH_SERVER . '/token',
        ];

        if ($registration) {
            $metadata['registration_endpoint'] = self::AUTH_SERVER . '/register';
        }

        Http::fake([
            'https://8.8.8.8/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => self::MCP_URL,
                'authorization_servers' => [self::AUTH_SERVER],
            ]),
            self::AUTH_SERVER . '/.well-known/oauth-authorization-server' => Http::response($metadata),
            self::AUTH_SERVER . '/register' => Http::response(['client_id' => 'registered-client'], 201),
            self::AUTH_SERVER . '/token' => Http::response([
                'access_token' => 'access-1',
                'refresh_token' => 'refresh-1',
                'expires_in' => 3600,
            ]),
            '*' => Http::response('', 404),
        ]);
    }

    private function oauthIntegration(): Integrations
    {
        return $this->makeIntegration([
            'url' => self::MCP_URL,
            'auth_methods' => ['oauth'],
        ]);
    }

    private function oauthTool(): Tool
    {
        return $this->makeMcpTool($this->oauthIntegration());
    }

    private function receiverFor(Agent $agent, Tool $tool): ReceiverWebhook
    {
        return new CreateMcpOAuthReceiverAction(
            agent: $agent,
            tool: $tool,
            user: $this->mcpUser,
        )->execute();
    }
}
