<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Http\SafeUrl;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Refresh-before-use for OAuth-protected MCP servers.
 *
 * NeuronAI's MCP transports take a static bearer and have no refresh, so the whole token lifecycle is
 * ours. The access token is short-lived and lives beside the refresh token in company settings, keyed
 * by `integrations_id`; the client credentials are platform-level and live on the APP, because the
 * OAuth application is registered by us once, not per tenant.
 *
 * The authorization-code leg (getting the FIRST refresh token) is a separate admin flow — this service
 * only keeps an existing grant alive. Without a stored refresh token it returns whatever access token
 * is on file and lets the 401 path mark the integration FAILED.
 */
class McpOAuthService
{
    /** Refresh this far ahead of expiry so a token cannot die mid-turn. */
    private const int EXPIRY_SKEW_SECONDS = 120;

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Integrations $integration,
    ) {
    }

    public function accessToken(): ?string
    {
        if (! $this->isExpired()) {
            return $this->stored(ConfigurationEnum::TOKEN_PREFIX);
        }

        $refreshed = $this->refresh();

        return $refreshed ?? $this->stored(ConfigurationEnum::TOKEN_PREFIX);
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->stored(ConfigurationEnum::OAUTH_EXPIRES_PREFIX);

        if ($expiresAt === null) {
            // No recorded expiry means we have never refreshed — treat as live and let a 401 correct us.
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->subSeconds(self::EXPIRY_SKEW_SECONDS)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Returns the new access token, or null when the grant cannot be renewed — the caller then lets
     * the request 401 and flips `integration_companies` to FAILED rather than throwing mid-turn.
     */
    public function refresh(): ?string
    {
        $refreshToken = $this->stored(ConfigurationEnum::OAUTH_REFRESH_PREFIX);
        $oauth = $this->oauthMetadata();
        $tokenUrl = trim((string) ($oauth['token_url'] ?? ''));

        if ($refreshToken === null || $tokenUrl === '') {
            return null;
        }

        $clientId = $this->appSetting('mcp_oauth_client_id_');
        $clientSecret = $this->appSetting('mcp_oauth_client_secret_');

        if ($clientId === null || $clientSecret === null) {
            return null;
        }

        try {
            SafeUrl::assertSafe($tokenUrl);

            $response = Http::asJson()
                ->timeout(15)
                ->withoutRedirecting()
                ->post($tokenUrl, [
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
        } catch (Throwable $e) {
            Log::warning('MCP OAuth refresh failed', [
                'integrations_id' => $this->integration->getId(),
                'companies_id' => $this->company->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($payload) || ! isset($payload['access_token'])) {
            return null;
        }

        $this->persist($payload);

        return (string) $payload['access_token'];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function persist(array $payload): void
    {
        $this->company->set(
            ConfigurationEnum::TOKEN_PREFIX->forIntegration($this->integration->getId()),
            (string) $payload['access_token']
        );

        // Rotating providers hand back a new refresh token; ones that do not simply omit it, and the
        // stored value must survive rather than be blanked.
        if (! empty($payload['refresh_token'])) {
            $this->company->set(
                ConfigurationEnum::OAUTH_REFRESH_PREFIX->forIntegration($this->integration->getId()),
                (string) $payload['refresh_token']
            );
        }

        if (isset($payload['expires_in'])) {
            $this->company->set(
                ConfigurationEnum::OAUTH_EXPIRES_PREFIX->forIntegration($this->integration->getId()),
                Carbon::now()->addSeconds((int) $payload['expires_in'])->toIso8601String()
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function oauthMetadata(): array
    {
        $metadata = is_array($this->integration->metadata) ? $this->integration->metadata : [];

        return is_array($metadata['oauth'] ?? null) ? $metadata['oauth'] : [];
    }

    private function stored(ConfigurationEnum $key): ?string
    {
        return $this->setting($this->company, $key->forIntegration($this->integration->getId()));
    }

    private function appSetting(string $prefix): ?string
    {
        return $this->setting($this->app, $prefix . $this->integration->getId());
    }

    /**
     * A settings read that cannot throw. `HashTableTrait::get()` reaches Redis and then the DB, and a
     * refresh is never the right place to surface that failure — a missing credential degrades to a
     * 401 the caller already handles.
     */
    private function setting(Apps|Companies $holder, string $key): ?string
    {
        try {
            $value = $holder->get($key);
        } catch (Throwable) {
            return null;
        }

        return Str::trimToNull(is_string($value) ? $value : null);
    }
}
