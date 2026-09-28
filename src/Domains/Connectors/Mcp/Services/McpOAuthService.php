<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Http\SafeUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Talks to an MCP server's token endpoint: the first authorization-code exchange, and refresh-before-use
 * after it — for one agent's grant.
 *
 * NeuronAI's MCP transports take a static bearer and have no refresh, so the whole token lifecycle is
 * ours. Requests are form-encoded — RFC 6749 requires it, and spec-compliant authorization servers reject
 * JSON — and carry `resource` (RFC 8707) unless the server's row turns it off: the MCP authorization spec
 * requires it so a token minted for one server cannot be replayed against another.
 *
 * The OAuth client is registered per app, not per agent: it identifies Kanvas to the vendor.
 */
class McpOAuthService
{
    /** Refresh this far ahead of expiry so a token cannot die mid-turn. */
    private const int EXPIRY_SKEW_SECONDS = 120;

    private ?McpCredentialService $credentials = null;

    /**
     * $serverUrl is only passed while connecting, before the agent's credential holds it; afterwards a
     * self-hosted server's address is read from the credential.
     */
    public function __construct(
        private readonly Agent $agent,
        private readonly Integrations $integration,
        private readonly ?string $serverUrl = null,
    ) {
    }

    public function accessToken(): ?string
    {
        if (! $this->isExpired()) {
            return $this->credentials()->rawToken();
        }

        return $this->refresh() ?? $this->credentials()->rawToken();
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->credentials()->expiresAt();

        if ($expiresAt === null) {
            // A grant stored without an expiry is treated as live; the 401 path corrects us if it is not.
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->subSeconds(self::EXPIRY_SKEW_SECONDS)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Returns the new access token, or null when the grant cannot be renewed — the caller then lets the
     * request 401 and marks this agent's connection failed rather than throwing mid-turn.
     */
    public function refresh(): ?string
    {
        $refreshToken = $this->credentials()->refreshToken();
        $client = $this->storedClient();

        if ($refreshToken === null || $client === null) {
            return null;
        }

        try {
            $grant = $this->requestGrant([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
                ...$this->clientParams($client),
            ]);
        } catch (Throwable $e) {
            Log::warning('MCP OAuth refresh failed', [
                'integrations_id' => $this->integration->getId(),
                'agents_id' => $this->agent->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->credentials()->store($grant['access_token'], $grant['refresh_token'], $grant['expires_in']);

        return $grant['access_token'];
    }

    /**
     * Trades an authorization code for the grant. Throws, unlike refresh(): this runs behind a consent
     * screen a person is watching, and they need the reason. Does not store — the caller hands the grant
     * to ConnectMcpServerAction, which stores it and proves it against the server.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): array
    {
        $client = $this->storedClient();

        if ($client === null) {
            throw new ValidationException('No OAuth client is registered for this MCP server — start the connection again.');
        }

        try {
            return $this->requestGrant([
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
                ...$this->clientParams($client),
            ]);
        } catch (Throwable $e) {
            throw new ValidationException('The MCP server refused the authorization code: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, string> $params
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    private function requestGrant(array $params): array
    {
        $endpoints = new McpOAuthDiscoveryService($this->integration, $this->resolvedServerUrl())->endpoints();

        // Re-checked at use: the endpoint may come from a cache entry older than a DNS change.
        SafeUrl::assertSafe($endpoints['token_endpoint']);

        if ($endpoints['resource'] !== null) {
            $params['resource'] = $endpoints['resource'];
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->withoutRedirecting()->post(
            $endpoints['token_endpoint'],
            $params
        );

        $payload = $response->json();

        if (! $response->successful() || ! is_array($payload) || ! is_string($payload['access_token'] ?? null)) {
            $reason = is_array($payload) ? (string) ($payload['error_description'] ?? $payload['error'] ?? '') : '';

            throw new ValidationException(trim('Token endpoint returned HTTP ' . $response->status() . '. ' . $reason));
        }

        $refreshToken = $payload['refresh_token'] ?? null;

        return [
            'access_token' => $payload['access_token'],
            'refresh_token' => is_string($refreshToken) && $refreshToken !== '' ? $refreshToken : null,
            'expires_in' => is_numeric($payload['expires_in'] ?? null) ? (int) $payload['expires_in'] : null,
        ];
    }

    /**
     * A public PKCE client has no secret, and sending an empty one fails client authentication.
     *
     * @param array{client_id: string, client_secret: string|null} $client
     * @return array<string, string>
     */
    private function clientParams(array $client): array
    {
        return $client['client_secret'] === null
            ? ['client_id' => $client['client_id']]
            : [
                'client_id' => $client['client_id'],
                'client_secret' => $client['client_secret'],
            ];
    }

    /**
     * @return array{client_id: string, client_secret: string|null}|null
     */
    private function storedClient(): ?array
    {
        return new McpOAuthClientService($this->agent->app, $this->integration, $this->resolvedServerUrl())->stored();
    }

    private function resolvedServerUrl(): ?string
    {
        return $this->serverUrl ?? $this->credentials()->serverUrl();
    }

    private function credentials(): McpCredentialService
    {
        return $this->credentials ??= new McpCredentialService($this->agent, $this->integration);
    }
}
