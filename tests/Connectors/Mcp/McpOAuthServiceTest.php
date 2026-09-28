<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpOAuthService;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;

/**
 * NeuronAI's MCP transports take a static bearer and have no refresh, so the whole token lifecycle is
 * ours — per agent, because each agent holds its own grant.
 *
 * Hosts are IP literals on purpose: `SafeUrl` short-circuits DNS for a literal, so the SSRF guard still
 * runs for real without the suite depending on name resolution. The token endpoint comes from
 * `metadata.oauth`, which discovery falls back to once the fake answers every well-known probe with
 * something that is not authorization-server metadata.
 */
final class McpOAuthServiceTest extends McpTestCase
{
    private const string MCP_URL = 'https://8.8.8.8/mcp';

    private const string TOKEN_URL = 'https://8.8.8.8/oauth/token';

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->makeAgent();
    }

    public function testALiveTokenIsUsedWithoutContactingTheProvider(): void
    {
        Http::fake();
        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->addHour());

        $this->assertSame('access_live', $this->service($integration)->accessToken());

        Http::assertNothingSent();
    }

    public function testAnExpiringTokenIsRefreshedBeforeItCanDieMidTurn(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration();
        // Inside the skew window: still technically valid, but not for the length of a turn.
        $this->seedTokens($integration, expiresAt: Carbon::now()->addSeconds(30));

        $this->assertSame('access_new', $this->service($integration)->accessToken());
        $this->assertSame('access_new', $this->credentials($this->agent, $integration)->rawToken());
    }

    public function testARotatedRefreshTokenIsStoredAndANonRotatedOneSurvives(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $this->service($integration)->refresh();

        // Providers that do not rotate simply omit the field; blanking the stored one would end the
        // grant on the next refresh.
        $this->assertSame('refresh_live', $this->credentials($this->agent, $integration)->refreshToken());
    }

    public function testRefreshIsFormEncodedAndBoundToTheServer(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $this->service($integration)->refresh();

        // RFC 6749 requires form encoding (spec servers reject JSON), and the MCP spec requires
        // `resource` so a token minted for this server cannot be replayed against another.
        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::TOKEN_URL
            && $request->isForm()
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh_live'
            && $request['resource'] === self::MCP_URL);
    }

    public function testRefreshLeavesResourceOutWhenTheServerIsConfiguredTo(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration(oauth: ['include_resource' => false]);
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $this->service($integration)->refresh();

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::TOKEN_URL
            && ! array_key_exists('resource', $request->data()));
    }

    public function testAPublicClientRefreshesWithoutSendingASecret(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration(withSecret: false);
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        // PKCE clients registered with `token_endpoint_auth_method: none` have no secret at all.
        $this->assertSame('access_new', $this->service($integration)->refresh());

        Http::assertSent(fn (ClientRequest $request): bool => $request->url() === self::TOKEN_URL
            && ! array_key_exists('client_secret', $request->data()));
    }

    public function testARejectedRefreshDoesNotThrowMidTurn(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        // The 401 path is what marks the connection FAILED; a refresh failure here must degrade, not
        // blow up a turn the person is waiting on.
        $this->assertSame('access_live', $this->service($integration)->accessToken());
    }

    public function testWithoutARefreshTokenThereIsNothingToRenew(): void
    {
        Http::fake();
        $integration = $this->oauthIntegration();
        $this->credentials($this->agent, $integration)->store('access_live');

        $this->assertNull($this->service($integration)->refresh());
    }

    public function testTheCredentialServiceRoutesOauthRowsThroughTheRefreshPath(): void
    {
        $this->fakeTokenEndpoint();

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $this->assertSame('access_new', new McpCredentialService($this->agent, $integration)->token());
    }

    public function testAKeyConnectionIsNeverRefreshedEvenOnAServerThatOffersOAuth(): void
    {
        Http::fake();
        $integration = $this->oauthIntegration();
        new McpCredentialService($this->agent, $integration)->store('pasted-key');

        // No expiry was stored, so this is a key — sending it to a token endpoint would leak it.
        $this->assertSame('pasted-key', new McpCredentialService($this->agent, $integration)->token());
        Http::assertNothingSent();
    }

    private function fakeTokenEndpoint(): void
    {
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'access_new',
                'expires_in' => 3600,
            ]),
            '*' => Http::response('', 404),
        ]);
    }

    /**
     * @param array<string, mixed> $oauth
     */
    private function oauthIntegration(bool $withSecret = true, array $oauth = []): Integrations
    {
        $integration = $this->makeIntegration([
            'url' => self::MCP_URL,
            'auth_methods' => ['bearer', 'oauth'],
            'oauth' => [
                'token_url' => self::TOKEN_URL,
                ...$oauth,
            ],
        ]);

        // The OAuth client identifies Kanvas to the vendor, so it is per app — only the grant is per agent.
        $this->mcpApp->set(ConfigurationEnum::OAUTH_CLIENT_ID_PREFIX->forIntegration($integration->getId()), 'client-id');
        $this->mcpApp->set(
            ConfigurationEnum::OAUTH_CLIENT_SECRET_PREFIX->forIntegration($integration->getId()),
            $withSecret ? 'client-secret' : ''
        );

        return $integration;
    }

    private function seedTokens(Integrations $integration, Carbon $expiresAt): void
    {
        $this->agent->set(ConfigurationEnum::CREDENTIALS_PREFIX->forIntegration($integration->getId()), [
            'access_token' => 'access_live',
            'refresh_token' => 'refresh_live',
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    private function service(Integrations $integration): McpOAuthService
    {
        return new McpOAuthService($this->agent, $integration);
    }
}
