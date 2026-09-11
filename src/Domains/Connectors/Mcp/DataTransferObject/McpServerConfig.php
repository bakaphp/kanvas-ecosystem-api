<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\DataTransferObject;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrl;
use Baka\Support\Str;
use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Enums\McpTransportEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\IntegrationTypeEnum;
use Kanvas\Workflow\Models\Integrations;

/**
 * The platform-side descriptor of one MCP server, read from `integrations.metadata`.
 *
 * Deliberately a plain value object rather than a Spatie Data class: it travels into queued refresh
 * jobs, and a `Data` subclass flattens typed properties on serialize.
 */
final readonly class McpServerConfig
{
    /**
     * @param string|null $url null when `urlPerConnection` — the address then comes from each agent's
     *                         connection (a self-hosted n8n, any open-source server a company runs)
     * @param list<McpAuthEnum> $authMethods every way the server can be connected — the admin picks one
     *                                       per agent (a pasted key, or a consent-screen click)
     * @param list<string> $exclude
     * @param array<string, mixed> $oauth optional `metadata.oauth` block — endpoint overrides, scopes,
     *                                    and vendor-specific authorize parameters
     */
    public function __construct(
        public ?string $url,
        public McpTransportEnum $transport,
        public array $authMethods,
        public string $prefix,
        public array $exclude = [],
        public int $timeoutMs = 20000,
        public ?string $vendor = null,
        public array $oauth = [],
        public bool $urlPerConnection = false,
    ) {
    }

    public static function fromIntegration(Integrations $integration): self
    {
        if ($integration->type !== IntegrationTypeEnum::MCP->value) {
            throw new ValidationException(
                'Integration "' . $integration->name . '" is not an MCP server (its type must be "mcp").'
            );
        }

        $metadata = is_array($integration->metadata) ? $integration->metadata : [];
        $urlPerConnection = ($metadata['url_per_connection'] ?? false) === true;
        $url = trim((string) ($metadata['url'] ?? ''));

        if (! $urlPerConnection && $url === '') {
            throw new ValidationException('MCP integration "' . $integration->name . '" has no metadata.url.');
        }

        return new self(
            url: $urlPerConnection ? null : $url,
            transport: McpTransportEnum::tryFrom((string) ($metadata['transport'] ?? '')) ?? McpTransportEnum::HTTP,
            authMethods: self::authMethodsFrom($metadata['auth_methods'] ?? null),
            prefix: trim((string) ($metadata['prefix'] ?? $integration->name)),
            exclude: array_values(array_map('strval', (array) ($metadata['exclude'] ?? []))),
            timeoutMs: (int) ($metadata['timeout_ms'] ?? 20000),
            vendor: isset($metadata['vendor']) ? (string) $metadata['vendor'] : null,
            oauth: is_array($metadata['oauth'] ?? null) ? $metadata['oauth'] : [],
            urlPerConnection: $urlPerConnection,
        );
    }

    /**
     * The one check for an address an admin types in. https only — the token travels to it — and through
     * the SSRF guard now, where the admin sees why; the transport re-checks on every connect.
     */
    public static function connectionUrl(?string $url, string $serverName): string
    {
        $url = Str::trimToNull($url);

        if ($url === null) {
            throw new ValidationException(sprintf('"%s" runs on your own server — provide its server_url.', $serverName));
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new ValidationException('server_url must be an https URL.');
        }

        try {
            SafeUrl::assertSafe($url);
        } catch (SsrfException) {
            throw new ValidationException('server_url points at an address Kanvas is not allowed to reach.');
        }

        return $url;
    }

    public function supports(McpAuthEnum $method): bool
    {
        return in_array($method, $this->authMethods, true);
    }

    public function isExcluded(string $remoteToolName): bool
    {
        return in_array($remoteToolName, $this->exclude, true);
    }

    /**
     * Unknown values are dropped rather than rejected, and a row naming none falls back to a key — the
     * only method every server can be reached with.
     *
     * @return list<McpAuthEnum>
     */
    private static function authMethodsFrom(mixed $values): array
    {
        $methods = [];

        foreach ((array) $values as $value) {
            $method = McpAuthEnum::tryFrom(is_string($value) ? $value : '');

            if ($method !== null) {
                $methods[$method->value] = $method;
            }
        }

        return $methods === [] ? [McpAuthEnum::BEARER] : array_values($methods);
    }
}
