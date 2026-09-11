<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use App\GraphQL\NervousSystem\Mutations\McpMutation;
use Baka\Http\Exceptions\SsrfException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Mcp\Actions\CreateMcpOAuthReceiverAction;
use Kanvas\Connectors\Mcp\OAuth\McpOAuthProvider;
use Kanvas\Connectors\Mcp\Services\McpOAuthClientService;
use Kanvas\Connectors\Mcp\Services\McpOAuthDiscoveryService;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use NeuronAI\MCP\McpTransportInterface;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Stubs\Connectors\Mcp\FakeMcpServer;

/**
 * Servers that run at each company's own address — a self-hosted n8n, any open-source MCP server — where
 * the admin supplies the URL per connection. The address is stored with the token, because some
 * providers put the secret in it, and every address is its own OAuth authorization server.
 */
final class McpServerUrlPerConnectionTest extends McpTestCase
{
    private const string SERVER = 'https://8.8.4.4/mcp';

    private const string OTHER_SERVER = 'https://9.9.9.9/mcp';

    protected function setUp(): void
    {
        parent::setUp();

        WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );
    }

    public function testTheServerUrlIsStoredWithTheAgentsToken(): void
    {
        $integration = $this->selfHostedIntegration();
        $agent = $this->makeAgent();

        $this->connectAgent($agent, $this->makeMcpTool($integration), serverUrl: self::SERVER);

        $credentials = $this->credentials($agent, $integration);
        $this->assertSame(self::SERVER, $credentials->serverUrl());
        $this->assertSame('agent-token', $credentials->rawToken());
    }

    public function testEachAgentPointsAtItsOwnServer(): void
    {
        $integration = $this->selfHostedIntegration();
        $tool = $this->makeMcpTool($integration);
        $first = $this->makeAgent();
        $second = $this->makeAgent();

        $this->connectAgent($first, $tool, serverUrl: self::SERVER);
        $this->connectAgent($second, $tool, serverUrl: self::OTHER_SERVER);

        $this->assertSame(self::SERVER, $this->credentials($first, $integration)->serverUrl());
        $this->assertSame(self::OTHER_SERVER, $this->credentials($second, $integration)->serverUrl());
    }

    #[DataProvider('refusedServerUrls')]
    public function testAMissingPlainOrPrivateServerUrlIsRefusedBeforeAnythingIsGranted(?string $url): void
    {
        $tool = $this->makeMcpTool($this->selfHostedIntegration());
        $agent = $this->makeAgent();

        try {
            $this->connectAgent($agent, $tool, serverUrl: $url);
            $this->fail('That server_url must be refused.');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNull($this->grantFor($agent, $tool));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function refusedServerUrls(): array
    {
        return [
            'missing' => [null],
            'plain http' => ['http://8.8.4.4/mcp'],
            'private address' => ['https://10.0.0.5/mcp'],
            'not a url' => ['n8n.internal'],
        ];
    }

    public function testTheTransportReadsTheUrlFromTheCredentialAndNeverSerializesIt(): void
    {
        $integration = $this->selfHostedIntegration();
        $agent = $this->makeAgent();
        // Stored directly: connecting would refuse a private address long before the transport saw it.
        $this->credentials($agent, $integration)->store('token', serverUrl: 'https://10.0.0.9/mcp');

        $transport = new GuardedHttpMcpTransport(
            url: null,
            agentsId: $agent->getId(),
            integrationsId: $integration->getId(),
        );

        $this->assertStringNotContainsString('10.0.0.9', serialize($transport));

        // Proves the address came from the credential — and that the guard runs on it at connect time.
        $this->expectException(SsrfException::class);

        $transport->connect();
    }

    public function testAServerWithAFixedAddressRefusesAServerUrl(): void
    {
        $tool = $this->makeMcpTool($this->makeIntegration());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('fixed address');

        new McpMutation()->connectServer(null, [
            'tool_id' => $tool->getId(),
            'agent_id' => $this->makeAgent()->getId(),
            'token' => 'key',
            'server_url' => self::SERVER,
        ]);
    }

    public function testReconnectingKeepsTheStoredServerUrl(): void
    {
        $integration = $this->selfHostedIntegration(['auth_methods' => ['oauth']]);
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $this->credentials($agent, $integration)->store('old', serverUrl: self::SERVER);

        $result = new McpMutation()->connectServer(null, [
            'tool_id' => $tool->getId(),
            'agent_id' => $agent->getId(),
        ]);

        $receiver = ReceiverWebhook::query()->where('uuid', substr($result['url'], strrpos($result['url'], '/') + 1))->first();

        $this->assertSame(self::SERVER, $receiver?->configuration['server_url']);
    }

    public function testEveryServerUrlIsDiscoveredAndRegisteredOnItsOwn(): void
    {
        Http::fake([
            'https://8.8.4.4/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://8.8.4.4')),
            'https://8.8.4.4/register' => Http::response(['client_id' => 'client-at-first'], 201),
            'https://9.9.9.9/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://9.9.9.9')),
            'https://9.9.9.9/register' => Http::response(['client_id' => 'client-at-second'], 201),
            '*' => Http::response('', 404),
        ]);

        $integration = $this->selfHostedIntegration(['auth_methods' => ['oauth']]);

        $first = new McpOAuthClientService($this->mcpApp, $integration, self::SERVER)->clientFor('https://kanvas.test/callback');
        $second = new McpOAuthClientService($this->mcpApp, $integration, self::OTHER_SERVER)->clientFor('https://kanvas.test/callback');

        $this->assertSame('client-at-first', $first['client_id']);
        $this->assertSame('client-at-second', $second['client_id']);
        $this->assertSame(
            'client-at-first',
            new McpOAuthClientService($this->mcpApp, $integration, self::SERVER)->stored()['client_id'] ?? null,
            'Registering the second address must not replace the first one\'s client.'
        );
        $this->assertSame(self::OTHER_SERVER, new McpOAuthDiscoveryService($integration, self::OTHER_SERVER)->endpoints()['resource']);
    }

    public function testTheOAuthCallbackStoresTheServerUrlWithTheGrant(): void
    {
        Http::fake([
            'https://8.8.4.4/.well-known/oauth-authorization-server' => Http::response($this->metadata('https://8.8.4.4')),
            'https://8.8.4.4/register' => Http::response(['client_id' => 'self-hosted-client'], 201),
            'https://8.8.4.4/token' => Http::response([
                'access_token' => 'self-hosted-access',
                'refresh_token' => 'self-hosted-refresh',
                'expires_in' => 3600,
            ]),
            '*' => Http::response('', 404),
        ]);

        $integration = $this->selfHostedIntegration(['auth_methods' => ['oauth']]);
        $tool = $this->makeMcpTool($integration);
        $agent = $this->makeAgent();
        $receiver = new CreateMcpOAuthReceiverAction(
            agent: $agent,
            tool: $tool,
            user: $this->mcpUser,
            serverUrl: self::SERVER,
        )->execute();

        $provider = new class () extends McpOAuthProvider {
            #[Override]
            protected function transportFor(): ?McpTransportInterface
            {
                return FakeMcpServer::listing(FakeMcpServer::twoTools());
            }
        };

        $url = $provider->getAuthorizationUrl($receiver, $this->mcpApp, Request::create('/'), 'nonce-self-hosted');
        $provider->handleCallback(
            $receiver,
            $this->mcpApp,
            Request::create('/callback', 'GET', ['code' => 'the-code', 'state' => 'nonce-self-hosted'])
        );

        $this->assertStringStartsWith('https://8.8.4.4/authorize?', $url);

        $credentials = $this->credentials($agent, $integration);
        $this->assertSame(self::SERVER, $credentials->serverUrl());
        $this->assertSame('self-hosted-access', $credentials->rawToken());
        $this->assertSame('oauth', $this->connectionState($agent, $tool)['auth']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function selfHostedIntegration(array $overrides = []): Integrations
    {
        return $this->makeIntegration([
            'url' => '',
            'url_per_connection' => true,
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function metadata(string $issuer): array
    {
        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/authorize',
            'token_endpoint' => $issuer . '/token',
            'registration_endpoint' => $issuer . '/register',
        ];
    }
}
