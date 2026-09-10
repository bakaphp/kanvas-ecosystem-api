<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\DataTransferObject;

use Kanvas\Connectors\Mcp\Enums\McpAuthEnum;
use Kanvas\Connectors\Mcp\Enums\McpTransportEnum;
use Kanvas\Exceptions\ValidationException;
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
     * @param list<string> $exclude
     */
    public function __construct(
        public string $url,
        public McpTransportEnum $transport,
        public McpAuthEnum $auth,
        public string $prefix,
        public array $exclude = [],
        public int $timeoutMs = 20000,
        public ?string $vendor = null,
    ) {
    }

    public static function fromIntegration(Integrations $integration): self
    {
        $metadata = is_array($integration->metadata) ? $integration->metadata : [];

        if (($metadata['kind'] ?? null) !== 'mcp') {
            throw new ValidationException(
                'Integration "' . $integration->name . '" is not an MCP server (metadata.kind must be "mcp").'
            );
        }

        $url = trim((string) ($metadata['url'] ?? ''));

        if ($url === '') {
            throw new ValidationException('MCP integration "' . $integration->name . '" has no metadata.url.');
        }

        return new self(
            url: $url,
            transport: McpTransportEnum::tryFrom((string) ($metadata['transport'] ?? '')) ?? McpTransportEnum::HTTP,
            auth: McpAuthEnum::tryFrom((string) ($metadata['auth'] ?? '')) ?? McpAuthEnum::BEARER,
            prefix: trim((string) ($metadata['prefix'] ?? $integration->name)),
            exclude: array_values(array_map('strval', (array) ($metadata['exclude'] ?? []))),
            timeoutMs: (int) ($metadata['timeout_ms'] ?? 20000),
            vendor: isset($metadata['vendor']) ? (string) $metadata['vendor'] : null,
        );
    }

    public function isExcluded(string $remoteToolName): bool
    {
        return in_array($remoteToolName, $this->exclude, true);
    }
}
