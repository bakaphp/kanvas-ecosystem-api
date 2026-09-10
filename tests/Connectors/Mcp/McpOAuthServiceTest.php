<?php

declare(strict_types=1);

namespace Tests\Connectors\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Services\McpCredentialService;
use Kanvas\Connectors\Mcp\Services\McpOAuthService;
use Kanvas\Workflow\Models\Integrations;

/**
 * NeuronAI's MCP transports take a static bearer and have no refresh, so the whole token lifecycle is
 * ours.
 *
 * The token endpoint is addressed by IP literal on purpose: `SafeUrl` short-circuits DNS for a literal,
 * so the SSRF guard still runs for real without the suite depending on name resolution.
 */
final class McpOAuthServiceTest extends McpTestCase
{
    private const string TOKEN_URL = 'https://8.8.8.8/oauth/token';

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
        Http::fake([
            '*' => Http::response(['access_token' => 'access_new', 'expires_in' => 3600]),
        ]);

        $integration = $this->oauthIntegration();
        // Inside the skew window: still technically valid, but not for the length of a turn.
        $this->seedTokens($integration, expiresAt: Carbon::now()->addSeconds(30));

        $this->assertSame('access_new', $this->service($integration)->accessToken());
        $this->assertSame(
            'access_new',
            $this->mcpCompany->get(ConfigurationEnum::TOKEN_PREFIX->forIntegration($integration->getId()))
        );
    }

    public function testARotatedRefreshTokenIsStoredAndANonRotatedOneSurvives(): void
    {
        Http::fake([
            '*' => Http::response(['access_token' => 'access_new', 'expires_in' => 3600]),
        ]);

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $this->service($integration)->refresh();

        // Providers that do not rotate simply omit the field; blanking the stored one would end the
        // grant on the next refresh.
        $this->assertSame(
            'refresh_live',
            $this->mcpCompany->get(ConfigurationEnum::OAUTH_REFRESH_PREFIX->forIntegration($integration->getId()))
        );
    }

    public function testARejectedRefreshDoesNotThrowMidTurn(): void
    {
        Http::fake(['*' => Http::response(['error' => 'invalid_grant'], 400)]);

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        // The 401 path is what marks the integration FAILED; a refresh failure here must degrade, not
        // blow up a turn the person is waiting on.
        $this->assertSame('access_live', $this->service($integration)->accessToken());
    }

    public function testWithoutARefreshTokenThereIsNothingToRenew(): void
    {
        Http::fake();
        $integration = $this->oauthIntegration();
        $this->mcpCompany->set(ConfigurationEnum::TOKEN_PREFIX->forIntegration($integration->getId()), 'access_live');

        $this->assertNull($this->service($integration)->refresh());
    }

    public function testTheCredentialServiceRoutesOauthRowsThroughTheRefreshPath(): void
    {
        Http::fake(['*' => Http::response(['access_token' => 'access_new', 'expires_in' => 3600])]);

        $integration = $this->oauthIntegration();
        $this->seedTokens($integration, expiresAt: Carbon::now()->subMinute());

        $token = new McpCredentialService($this->mcpApp, $this->mcpCompany, $integration)->token();

        $this->assertSame('access_new', $token);
    }

    private function oauthIntegration(): Integrations
    {
        $integration = $this->makeIntegration([
            'auth' => 'oauth',
            'oauth' => ['token_url' => self::TOKEN_URL],
        ]);

        // Client credentials are platform-level: the OAuth application is registered once by us, not
        // per tenant.
        $this->mcpApp->set('mcp_oauth_client_id_' . $integration->getId(), 'client-id');
        $this->mcpApp->set('mcp_oauth_client_secret_' . $integration->getId(), 'client-secret');

        return $integration;
    }

    private function seedTokens(Integrations $integration, Carbon $expiresAt): void
    {
        $id = $integration->getId();
        $this->mcpCompany->set(ConfigurationEnum::TOKEN_PREFIX->forIntegration($id), 'access_live');
        $this->mcpCompany->set(ConfigurationEnum::OAUTH_REFRESH_PREFIX->forIntegration($id), 'refresh_live');
        $this->mcpCompany->set(ConfigurationEnum::OAUTH_EXPIRES_PREFIX->forIntegration($id), $expiresAt->toIso8601String());
    }

    private function service(Integrations $integration): McpOAuthService
    {
        return new McpOAuthService($this->mcpApp, $this->mcpCompany, $integration);
    }
}
