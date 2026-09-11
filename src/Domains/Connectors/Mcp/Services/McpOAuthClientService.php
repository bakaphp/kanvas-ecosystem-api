<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Http\SafeUrl;
use Baka\Support\Str;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Mcp\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * The OAuth client Kanvas presents to one MCP server: self-registered (RFC 7591) as a public PKCE client,
 * per app — and per address for a self-hosted server. A client id set by hand is honoured as-is.
 *
 * Re-registered when the callback URL or the authorization server changes: a client exists only at the
 * server that registered it, for its redirect_uri, and a mismatch shows up as an opaque error on the
 * vendor's consent page, not here.
 */
class McpOAuthClientService
{
    public function __construct(
        private readonly Apps $app,
        private readonly Integrations $integration,
        private readonly ?string $serverUrl = null,
    ) {
    }

    /**
     * @return array{client_id: string, client_secret: string|null}
     */
    public function clientFor(string $redirectUri): array
    {
        $stored = $this->stored();
        $registeredFor = $this->setting(ConfigurationEnum::OAUTH_REDIRECT_URI_PREFIX);

        // No recorded redirect means the client was set by hand on the app.
        if ($stored !== null && $registeredFor === null) {
            return $stored;
        }

        $endpoint = new McpOAuthDiscoveryService($this->integration, $this->serverUrl)->endpoints()['registration_endpoint'];

        if (
            $stored !== null
            && $registeredFor === $redirectUri
            && $this->setting(ConfigurationEnum::OAUTH_REGISTRATION_ENDPOINT_PREFIX) === $endpoint
        ) {
            return $stored;
        }

        return $this->register($redirectUri, $endpoint);
    }

    /**
     * The stored client, never registering — a token refresh must not create clients.
     *
     * @return array{client_id: string, client_secret: string|null}|null
     */
    public function stored(): ?array
    {
        $clientId = $this->setting(ConfigurationEnum::OAUTH_CLIENT_ID_PREFIX);

        if ($clientId === null) {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $this->setting(ConfigurationEnum::OAUTH_CLIENT_SECRET_PREFIX),
        ];
    }

    /**
     * @return array{client_id: string, client_secret: string|null}
     */
    private function register(string $redirectUri, ?string $endpoint): array
    {
        if ($endpoint === null) {
            throw new ValidationException(sprintf(
                'MCP server "%s" does not support dynamic client registration. Register a client with the vendor and set %s on the app.',
                $this->integration->name,
                $this->key(ConfigurationEnum::OAUTH_CLIENT_ID_PREFIX)
            ));
        }

        SafeUrl::assertSafe($endpoint);

        try {
            $response = Http::asJson()->acceptJson()->timeout(15)->withoutRedirecting()->post($endpoint, [
                'client_name' => 'Kanvas',
                'redirect_uris' => [$redirectUri],
                'grant_types' => [
                    'authorization_code',
                    'refresh_token',
                ],
                'response_types' => ['code'],
                'token_endpoint_auth_method' => 'none',
            ]);
        } catch (Throwable $e) {
            throw new ValidationException('MCP client registration failed: ' . $e->getMessage());
        }

        $payload = $response->successful() ? $response->json() : null;
        $clientId = is_array($payload) ? Str::trimToNull(is_string($payload['client_id'] ?? null) ? $payload['client_id'] : null) : null;

        if ($clientId === null) {
            throw new ValidationException('MCP client registration was rejected (HTTP ' . $response->status() . ').');
        }

        $secret = is_string($payload['client_secret'] ?? null) ? $payload['client_secret'] : '';

        $this->app->set($this->key(ConfigurationEnum::OAUTH_CLIENT_ID_PREFIX), $clientId);
        // Written even when empty, so a secret left over from an earlier registration is never sent
        // alongside this client.
        $this->app->set($this->key(ConfigurationEnum::OAUTH_CLIENT_SECRET_PREFIX), $secret);
        $this->app->set($this->key(ConfigurationEnum::OAUTH_REDIRECT_URI_PREFIX), $redirectUri);
        $this->app->set($this->key(ConfigurationEnum::OAUTH_REGISTRATION_ENDPOINT_PREFIX), $endpoint);

        return [
            'client_id' => $clientId,
            'client_secret' => Str::trimToNull($secret),
        ];
    }

    /**
     * A read that cannot throw: every caller degrades to "no client", an error it already explains,
     * rather than surfacing a Redis or DB failure.
     */
    private function setting(ConfigurationEnum $key): ?string
    {
        try {
            $value = $this->app->get($this->key($key));
        } catch (Throwable) {
            return null;
        }

        return Str::trimToNull(is_string($value) ? $value : null);
    }

    private function key(ConfigurationEnum $key): string
    {
        return $key->forServer($this->integration->getId(), $this->serverUrl);
    }
}
