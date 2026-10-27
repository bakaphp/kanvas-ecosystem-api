<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * The only reader and writer of one agent's credential for one MCP server — the agent's own vendor
 * login, never the connecting admin's — kept as one custom field per server.
 *
 * Custom fields carry no visibility flag, so anyone who can read an agent's custom fields can read this
 * token — the same exposure the Slack and GitHub tokens already stored there have.
 */
class McpCredentialService
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Integrations $integration,
    ) {
    }

    /**
     * Only an OAuth grant is refreshed. Decided by the stored credential, not the server's row: a server
     * can offer both methods, and a pasted key must never reach a token endpoint.
     */
    public function token(): ?string
    {
        if ($this->isOAuthGrant()) {
            return new McpOAuthService($this->agent, $this->integration)->accessToken();
        }

        return $this->rawToken();
    }

    public function rawToken(): ?string
    {
        return $this->field('access_token');
    }

    public function refreshToken(): ?string
    {
        return $this->field('refresh_token');
    }

    public function expiresAt(): ?string
    {
        return $this->field('expires_at');
    }

    /**
     * Kept with the token, not on the grant: some providers put the secret in the URL.
     */
    public function serverUrl(): ?string
    {
        return $this->field('server_url');
    }

    /**
     * An OAuth access token is a bearer token by definition (RFC 6750) and travels as one even on a
     * server whose pasted key goes elsewhere — Apollo reads keys from `X-Api-Key` and OAuth tokens
     * from `Authorization`. The expiry fallback covers credentials that carry no `auth` field: only an
     * OAuth grant is ever stored with one.
     */
    public function isOAuthGrant(): bool
    {
        return $this->field('auth') === McpAuthEnum::OAUTH->value || $this->expiresAt() !== null;
    }

    /**
     * A null keeps the stored value: a provider that does not rotate omits the refresh token on refresh,
     * and a refresh never changes the method the grant was obtained with. A new grant calls forget()
     * first, so the previous grant's expiry cannot outlive it.
     *
     * Workflows are off because every custom-field write fires CREATE_CUSTOM_FIELD, and refreshes write
     * hourly per agent per server — events no rule is waiting for.
     */
    public function store(
        ?string $accessToken,
        ?string $refreshToken = null,
        ?int $expiresIn = null,
        ?string $serverUrl = null,
        ?McpAuthEnum $method = null
    ): void {
        $credentials = $this->credentials();

        // Null on the `none` path, where the address IS the credential and there is no token to keep.
        if ($accessToken !== null) {
            $credentials['access_token'] = $accessToken;
        }

        if ($refreshToken !== null) {
            $credentials['refresh_token'] = $refreshToken;
        }

        if ($expiresIn !== null) {
            $credentials['expires_at'] = Carbon::now()->addSeconds($expiresIn)->toIso8601String();
        }

        if ($serverUrl !== null) {
            $credentials['server_url'] = $serverUrl;
        }

        if ($method !== null) {
            $credentials['auth'] = $method->value;
        }

        $this->agent->disableWorkflows();

        try {
            $this->agent->set($this->key(), $credentials);
        } finally {
            $this->agent->enableWorkflows();
        }
    }

    public function forget(): void
    {
        $this->agent->del($this->key());
    }

    /**
     * @return array<string, mixed>
     */
    private function credentials(): array
    {
        try {
            $value = $this->agent->get($this->key());
        } catch (Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    private function field(string $name): ?string
    {
        $value = $this->credentials()[$name] ?? null;

        return Str::trimmedStringOrNull($value);
    }

    private function key(): string
    {
        return ConfigurationEnum::CREDENTIALS_PREFIX->forIntegration($this->integration->getId());
    }
}
