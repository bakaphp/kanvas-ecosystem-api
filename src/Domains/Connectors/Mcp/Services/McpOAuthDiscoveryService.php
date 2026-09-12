<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Baka\Http\SafeUrl;
use Baka\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\Integrations;
use Throwable;

/**
 * Discovers an MCP server's OAuth endpoints as the MCP authorization spec requires — remote MCP servers
 * run their own authorization servers, separate from each vendor's REST OAuth. Order: RFC 9728
 * protected-resource metadata names the server, RFC 8414 metadata on it names the endpoints,
 * `metadata.oauth` fills gaps, and the 2025-03-26 spec defaults on the origin come last.
 *
 * Every URL here is chosen by a remote server, so each passes the SSRF guard; an authorization server on
 * a private address aborts discovery instead of falling back.
 */
class McpOAuthDiscoveryService
{
    private const int CACHE_SECONDS = 86400;

    /**
     * $serverUrl: a self-hosted server's address — each one is its own authorization server, cached apart.
     */
    public function __construct(
        private readonly Integrations $integration,
        private readonly ?string $serverUrl = null,
    ) {
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: string|null, resource: string|null}
     */
    public function endpoints(): array
    {
        return Cache::remember($this->cacheKey(), self::CACHE_SECONDS, fn (): array => $this->discover());
    }

    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string, registration_endpoint: string|null, resource: string|null}
     */
    private function discover(): array
    {
        $config = McpServerConfig::fromIntegration($this->integration);
        $resource = $this->serverUrl ?? $config->url;

        if ($resource === null) {
            throw new ValidationException(sprintf(
                '"%s" runs on each company\'s own server — its OAuth endpoints need the connection\'s server_url.',
                $this->integration->name
            ));
        }

        $origin = $this->origin($resource);
        $overrides = $config->oauth;

        // `authorization_server` pins the server instead of following RFC 9728 — for a vendor whose
        // advertised server refuses spec clients while another one it runs accepts them.
        $issuer = $this->nonEmpty($overrides['authorization_server'] ?? null)
            ?? $this->authorizationServer($origin, $this->path($resource))
            ?? $origin;
        $metadata = $this->authorizationServerMetadata(rtrim($issuer, '/'));

        // With no metadata at all, the 2025-03-26 spec defaults apply — registration included. Metadata
        // that exists but names no registration endpoint means the server does not do DCR at all.
        $defaultRegistration = $metadata === [] ? $origin . '/register' : null;

        $endpoints = [
            'authorization_endpoint' => $this->nonEmpty($metadata['authorization_endpoint'] ?? null)
                ?? $this->nonEmpty($overrides['authorize_url'] ?? null)
                ?? $origin . '/authorize',
            'token_endpoint' => $this->nonEmpty($metadata['token_endpoint'] ?? null)
                ?? $this->nonEmpty($overrides['token_url'] ?? null)
                ?? $origin . '/token',
            'registration_endpoint' => $this->nonEmpty($metadata['registration_endpoint'] ?? null)
                ?? $this->nonEmpty($overrides['registration_url'] ?? null)
                ?? $defaultRegistration,
            // RFC 8707 `resource` is on by default, as the MCP spec requires; `include_resource: false`
            // drops it for an authorization server that rejects it.
            'resource' => ($overrides['include_resource'] ?? true) === false ? null : $resource,
        ];

        foreach (['authorization_endpoint', 'token_endpoint', 'registration_endpoint'] as $key) {
            if ($endpoints[$key] !== null) {
                SafeUrl::assertSafe($endpoints[$key]);
            }
        }

        return $endpoints;
    }

    /**
     * RFC 9728: the path-aware well-known URI first, then the origin-level one.
     */
    private function authorizationServer(string $origin, string $path): ?string
    {
        $candidates = $path !== ''
            ? [
                $origin . '/.well-known/oauth-protected-resource' . $path,
                $origin . '/.well-known/oauth-protected-resource',
            ]
            : [$origin . '/.well-known/oauth-protected-resource'];

        $servers = $this->firstDocument($candidates, 'authorization_servers')['authorization_servers'] ?? null;

        if (! is_array($servers)) {
            return null;
        }

        foreach ($servers as $server) {
            $server = $this->nonEmpty($server);

            if ($server !== null) {
                return rtrim($server, '/');
            }
        }

        return null;
    }

    /**
     * RFC 8414 inserts the well-known segment between host and path; OpenID Connect discovery is tried
     * in both of its placements after it, because authorization servers publish either.
     *
     * @return array<string, mixed>
     */
    private function authorizationServerMetadata(string $issuer): array
    {
        $origin = $this->origin($issuer);
        $path = $this->path($issuer);

        $candidates = $path !== ''
            ? [
                $origin . '/.well-known/oauth-authorization-server' . $path,
                $origin . '/.well-known/openid-configuration' . $path,
                $origin . $path . '/.well-known/openid-configuration',
            ]
            : [
                $origin . '/.well-known/oauth-authorization-server',
                $origin . '/.well-known/openid-configuration',
            ];

        return $this->firstDocument($candidates, 'token_endpoint') ?? [];
    }

    /**
     * A 404 or a dead host is normal while probing well-known locations and just means "try the next".
     * A URL that fails the SSRF guard is not: the guard runs outside the try so it aborts discovery.
     *
     * @param list<string> $urls
     * @return array<string, mixed>|null
     */
    private function firstDocument(array $urls, string $requiredKey): ?array
    {
        foreach ($urls as $url) {
            SafeUrl::assertSafe($url);

            try {
                $response = Http::timeout(10)->acceptJson()->withoutRedirecting()->get($url);
            } catch (Throwable) {
                continue;
            }

            $document = $response->successful() ? $response->json() : null;

            if (is_array($document) && isset($document[$requiredKey])) {
                return $document;
            }
        }

        return null;
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');

        return isset($parts['port']) ? $origin . ':' . $parts['port'] : $origin;
    }

    private function path(string $url): string
    {
        return rtrim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
    }

    private function nonEmpty(mixed $value): ?string
    {
        return Str::trimmedStringOrNull($value);
    }

    private function cacheKey(): string
    {
        $key = 'mcp:oauth:endpoints:' . $this->integration->getId();

        return $this->serverUrl === null ? $key : $key . ':' . substr(hash('sha256', $this->serverUrl), 0, 16);
    }
}
